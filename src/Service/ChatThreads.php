<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatThreadRead;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatThreadReadRepository;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

use function array_keys;
use function array_slice;
use function count;
use function min;

use const DATE_ATOM;

/**
 * 1.36 (Beacon, D-20261118-02): one-level threads (`message_menu.threads`). A reply is a ChatMessage of the same
 * conversation with a thread root; it stays out of the main timeline and its unread badge, its root counts it, and its
 * followers (the root's author, the repliers, the people a reply names with @mention; @channel and @here notify without following) get its Web Push and a "new replies" badge.
 * Access (VIEW, ChatVoter::THREAD) is the caller's job. Stateless.
 */
final readonly class ChatThreads
{
    /** Replies per page of a thread. */
    public const int PAGE = 30;

    private TransactionRunnerInterface $transactions;

    /**
     * @param TransactionRunnerInterface|null $transactions ChatKit's runner; null uses the default one
     */
    public function __construct(
        private ChatService $chat,
        private ChatMessageRepository $messages,
        private ChatThreadReadRepository $reads,
        private ChatIdempotency $idempotency,
        private ChatMenuOptions $options,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock = new Clock(),
        ?TransactionRunnerInterface $transactions = null,
    ) {
        $this->transactions = $transactions ?? new ChatKitTransactionRunner($entityManager);
    }

    /**
     * Whether threads are offered.
     */
    public function enabled(): bool
    {
        return $this->options->threadsEnabled();
    }

    /**
     * The root of the thread a message belongs to (itself for a main-timeline message).
     *
     * @param ChatMessage $message The message
     */
    public static function rootOf(ChatMessage $message): ChatMessage
    {
        return $message->getThreadRoot() ?? $message;
    }

    /**
     * A page of the thread, oldest first, with the viewer's read cursor.
     *
     * @param ChatUserInterface $viewer The viewer
     * @param ChatMessage $root The root
     * @param int|null $before Replies older than this id (the older page)
     * @param int|null $after Replies newer than this id (catch-up)
     *
     * @return array{replies: list<ChatMessage>, hasMore: bool, readUpTo: int|null, following: bool}
     */
    public function page(ChatUserInterface $viewer, ChatMessage $root, ?int $before, ?int $after): array
    {
        $page    = $this->messages->findReplies($root, self::PAGE + 1, $before, $after);
        $hasMore = count($page) > self::PAGE;
        if ($hasMore) {
            $page = $after === null ? array_slice($page, 1) : array_slice($page, 0, self::PAGE);
        }
        $row = $this->reads->findOneFor($root, $viewer);

        return ['replies' => $page, 'hasMore' => $hasMore, 'readUpTo' => $row?->getLastReadReplyId(), 'following' => $row?->isFollowing() ?? false];
    }

    /**
     * Posts a reply (the send pipeline: mentions, references, links, Web Push to the followers), under an
     * `Idempotency-Key`: the same key and text again answer the first reply without a second one.
     *
     * @param ChatUserInterface $author The writer (SEND on the conversation)
     * @param ChatMessage $root The root (a main-timeline text message: ChatVoter::THREAD)
     * @param string $body Raw Markdown
     * @param string $key The `Idempotency-Key`
     * @param string|null $cardContext 2.6 (audit ZCI-M1): the client API context of the write (card `canShare()` scope)
     * @param bool $cardApi 2.6 (audit ZCI-M1): written through the client API (card `canShare()` scope)
     *
     * @throws InvalidArgumentException `empty`, `too_long`, `invalid_link`, `not_threadable`, `idempotency_mismatch`, (2.6) `card_not_allowed`
     *
     * @return array{ChatMessage, bool} The reply, and whether an earlier request with the key created it
     */
    public function reply(ChatUserInterface $author, ChatMessage $root, string $body, string $key, ?string $cardContext = null, bool $cardApi = false): array
    {
        $root = self::rootOf($root);
        if (!$root->canStartThread()) {
            throw new InvalidArgumentException('not_threadable');
        }
        [$messages, $replayed] = $this->idempotency->run(
            $author,
            $key,
            ChatIdempotency::hash(['thread', $root->getUuid(), $body]),
            fn (Closure $before, Closure $after): array => $this->chat->post($author, [new ChatDraft($root->getConversation(), $body, $root, null, $cardContext, $cardApi)], $before, $after),
        );

        return [$messages[0] ?? throw new InvalidArgumentException('not_found'), $replayed];
    }

    /**
     * Moves the viewer's read cursor of a thread forward (never back, never past its newest reply) and follows it.
     *
     * @param ChatUserInterface $user The reader
     * @param ChatMessage $root The root
     * @param int $replyId The newest reply read
     *
     * @return bool True when the cursor moved
     */
    public function markRead(ChatUserInterface $user, ChatMessage $root, int $replyId): bool
    {
        $latest = $this->messages->latestReplyIds([(int) $root->getId()])[(int) $root->getId()] ?? null;
        if ($latest === null || $replyId < 1) {
            return false;
        }
        $replyId = min($replyId, $latest);
        $now     = $this->clock->now();

        return ChatKitWrites::runRecovering($this->transactions, $this->entityManager, function () use ($user, $root, $replyId, $now): bool {
            $root = ChatKitWrites::attach($this->entityManager, $root);

            return $this->reads->readUpTo($this->reads->claim($root, $user, $now), $replyId, $now);
        });
    }

    /**
     * The thread summary of the roots of a timeline page for the viewer: reply count, last reply time, the newest
     * repliers and whether new replies wait (followed threads only). One query per kind of data. Threads on.
     *
     * @param ChatUserInterface $viewer The viewer
     * @param list<ChatMessage> $page The page
     *
     * @return array<string, array{count: int, lastReplyAt: string|null, unread: bool, following: bool, repliers: list<ChatUserInterface>}> By root uuid (roots with replies only)
     */
    public function summaries(ChatUserInterface $viewer, array $page): array
    {
        $roots = [];
        foreach ($page as $message) {
            if ($message->getThreadReplyCount() > 0) {
                $roots[(int) $message->getId()] = $message;
            }
        }
        if ($roots === []) {
            return [];
        }
        $ids      = array_keys($roots);
        $rows     = $this->reads->rowsAmong($viewer, $ids);
        $latest   = $this->messages->latestReplyIds($ids);
        $repliers = $this->messages->recentRepliers($ids);
        $out      = [];
        foreach ($roots as $id => $root) {
            $row                   = $rows[$id] ?? null;
            $following             = $row instanceof ChatThreadRead && $row->isFollowing();
            $out[$root->getUuid()] = [
                'count'       => $root->getThreadReplyCount(),
                'lastReplyAt' => $root->getThreadLastReplyAt() instanceof DateTimeImmutable ? $root->getThreadLastReplyAt()->format(DATE_ATOM) : null,
                'unread'      => $following && isset($latest[$id]) && $latest[$id] > (int) $row->getLastReadReplyId(),
                'following'   => $following,
                'repliers'    => $repliers[$id] ?? [],
            ];
        }

        return $out;
    }
}

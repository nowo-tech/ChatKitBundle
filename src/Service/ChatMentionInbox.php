<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Contract\ChatMentionInboxInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Model\ChatMentionView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

use function array_chunk;
use function array_keys;
use function array_map;
use function is_int;
use function is_string;

/**
 * 2.0: default ChatMentionInboxInterface (aliased to it): the mention repository's queries answered as views, and the
 * mark-read writes by user key on ChatKit's runner. Stateless (FrankenPHP worker mode: nothing is memoised).
 */
final readonly class ChatMentionInbox implements ChatMentionInboxInterface
{
    /** Mention ids per statement. */
    public const int CHUNK = 500;

    private TransactionRunnerInterface $transactions;

    /**
     * @param ChatMessageMentionRepository $mentions The mention rows (ChatKit's private manager)
     * @param EntityManagerInterface $entityManager ChatKit's private entity manager
     * @param TransactionRunnerInterface|null $transactions ChatKit's runner; null builds the default one
     * @param ClockInterface $clock Read time
     */
    public function __construct(
        private ChatMessageMentionRepository $mentions,
        private EntityManagerInterface $entityManager,
        ?TransactionRunnerInterface $transactions = null,
        private ClockInterface $clock = new Clock(),
    ) {
        $this->transactions = $transactions ?? new ChatKitTransactionRunner($entityManager);
    }

    /**
     * {@inheritDoc}
     */
    public function mentions(ChatUserInterface $user, array $channelKeys, bool $supportAgent, int $limit, bool $unreadOnly = false, ?DateTimeImmutable $since = null, bool $channelsOnly = false): array
    {
        return array_map(
            static fn ($mention): ChatMentionView => ChatMentionView::of($mention, $user),
            $this->mentions->findForUser($user, $channelKeys, $supportAgent, $limit, $unreadOnly, $since, $channelsOnly),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function countMentions(ChatUserInterface $user, array $channelKeys, bool $supportAgent, bool $unreadOnly = false, ?DateTimeImmutable $since = null, bool $channelsOnly = false): int
    {
        return $this->mentions->countForUser($user, $channelKeys, $supportAgent, $unreadOnly, $since, $channelsOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function markMentionRead(ChatUserInterface|string $user, int|string $mentionId): bool
    {
        return $this->mark($user, [$mentionId])['owned'] !== [];
    }

    /**
     * {@inheritDoc}
     */
    public function markMentionsRead(ChatUserInterface|string $user, array $mentionIds): int
    {
        return $this->mark($user, $mentionIds)['marked'];
    }

    /**
     * @param array<array-key, mixed> $mentionIds
     *
     * @return array{owned: list<int>, marked: int}
     */
    private function mark(ChatUserInterface|string $user, array $mentionIds): array
    {
        $key = is_string($user) ? $user : ChatUserReferences::key($user);
        $ids = [];
        foreach ($mentionIds as $id) {
            // Only positive integers, or their canonical decimal strings ("007", " 7", "7.0" are nobody's id).
            if (is_int($id) && $id > 0) {
                $ids[$id] = true;
            } elseif (is_string($id) && $id !== '0' && ChatUserReferences::isCanonicalId($id)) {
                $ids[(int) $id] = true;
            }
        }
        if ($key === null || $key === '' || $ids === []) {
            return ['owned' => [], 'marked' => 0];
        }
        $now = $this->clock->now();

        return ChatKitWrites::runRecovering($this->transactions, $this->entityManager, function () use ($key, $ids, $now): array {
            $result = ['owned' => [], 'marked' => 0];
            // Bounded `IN` lists.
            foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
                $done   = $this->mentions->markReadByKey($key, $chunk, $now);
                $result = ['owned' => [...$result['owned'], ...$done['owned']], 'marked' => $result['marked'] + $done['marked']];
            }

            return $result;
        });
    }
}

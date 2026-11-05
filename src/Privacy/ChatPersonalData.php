<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Privacy;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Event\ChatMentionsErasedEvent;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatMentions;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_count_values;
use function array_filter;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strtr;

use const DATE_ATOM;

/**
 * Personal data hooks for the application's export / erasure flows (GDPR). Export: the user's own messages
 * and chat settings (other people's messages are not the user's personal data). Erasure: authored messages
 * become tombstones with no author and no text (the thread keeps its shape for the others), reactions, read
 * cursors, settings, mention rows and the user's own support tickets are deleted, @mentions of the user in
 * other people's messages lose the user's name and public id, the pinned-by / deleted-by / assignee / resolved-by
 * pointers to the user are cleared, and support bot lines in other people's tickets lose the user's name.
 */
final readonly class ChatPersonalData
{
    /** What a mention of an erased user becomes by default (1.18: public). */
    public const string ERASED_MENTION = '@…';

    /** Messages loaded, rewritten and flushed at a time by {@see self::eraseMentions()} (1.18). */
    public const int ERASE_BATCH = 200;

    /**
     * An erased-mention label that could render as a mention, alone or with the text around the token (1.18): empty, a
     * bracket or parenthesis anywhere, a handle character first, an `@` followed by a handle character or `[`, or an `@`
     * last.
     */
    private const string MENTION_LIKE_LABEL = '/^$|[\[\]()]|^[a-zA-Z0-9_]|@[a-zA-Z0-9_]|@$/';

    /**
     * Builds the personal data hooks from the entity manager and the chat repositories.
     *
     * @param EntityManagerInterface $entityManager Runs the export queries and the bulk erasure
     * @param ChatUserSettingsRepository $settings Loads the user's chat settings for the export
     * @param ChatConversationRepository $conversations Finds the user's own support thread for the erasure
     * @param ClockInterface $clock Clock stamping the erasure tombstones
     * @param ChatNotifier|null $notifier Publishes `message_updated` for the messages whose mentions were rewritten; null publishes nothing
     * @param ChatReactionRepository|null $reactions Reactions sent with those updates; null sends none
     * @param ChatMarkdownRenderer $markdown Re-renders the HTML of the rewritten messages
     * @param EventDispatcherInterface|null $events Receives {@see ChatMentionsErasedEvent} (1.18); null dispatches none
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatUserSettingsRepository $settings,
        private ChatConversationRepository $conversations,
        private ClockInterface $clock = new Clock(),
        private ?ChatNotifier $notifier = null,
        private ?ChatReactionRepository $reactions = null,
        private ChatMarkdownRenderer $markdown = new ChatMarkdownRenderer(),
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * Exports the user's chat settings and their own non-deleted messages, oldest first.
     *
     * @param ChatUserInterface $user The user whose data is exported
     *
     * @return array{settings: array<string, mixed>, quick_replies: list<array{title: string, text: string}>, messages: list<array<string, string|null>>, pins: list<array<string, string|null>>} Settings, authored messages and the pins the user made
     */
    public function export(ChatUserInterface $user): array
    {
        /** @var list<ChatMessage> $messages */
        $messages = $this->entityManager->createQueryBuilder()
            ->select('m', 'c')->from(ChatMessage::class, 'm')->innerJoin('m.conversation', 'c')
            ->where('m.author = :user AND m.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('m.id', 'ASC')
            ->getQuery()->getResult();
        $settings = $this->settings->forUser($user);
        $now      = $this->clock->now();
        /** @var list<ChatMessage> $pins */
        $pins = $this->entityManager->createQueryBuilder()
            ->select('m', 'c')->from(ChatMessage::class, 'm')->innerJoin('m.conversation', 'c')
            ->where('m.pinnedBy = :user AND m.pinnedAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('m.pinnedAt', 'ASC')
            ->getQuery()->getResult();

        return [
            'settings' => [
                'sound'                 => $settings->getSound(),
                'sound_mention'         => $settings->getSoundFor('mention'),
                'sound_buzz'            => $settings->getSoundFor('buzz'),
                'sound_support'         => $settings->getSoundFor('support'),
                'volume'                => $settings->getVolume(),
                'sound_background_only' => $settings->isSoundBackgroundOnly(),
                'push'                  => $settings->isPushEnabled(),
                'push_preview'          => $settings->isPushPreview(),
                'buzzes'                => $settings->isBuzzEnabled(),
                'buzz_open_window'      => $settings->isBuzzOpenWindow(),
                'open_on_message'       => $settings->isOpenOnMessage(),
                'send_key'              => $settings->getSendKey(),
                'email_digest'          => $settings->isEmailDigest(),
                'show_online'           => $settings->isPresenceVisible(),
                'status'                => $settings->getStatus($now),
                'status_text'           => $settings->getStatusText($now),
                'status_emoji'          => $settings->getStatusEmoji($now),
                'status_expires_at'     => $settings->getStatusExpiresAt($now)?->format(DATE_ATOM),
                // 1.11
                'auto_reply' => $settings->autoReplyView(),
            ],
            'quick_replies' => array_map(
                static fn (ChatQuickReply $reply): array => ['title' => $reply->getTitle(), 'text' => $reply->getBody()],
                $this->entityManager->getRepository(ChatQuickReply::class)->findBy(['user' => $user], ['id' => 'ASC']),
            ),
            'messages' => array_map(static fn (ChatMessage $message): array => [
                'conversation_uuid' => $message->getConversation()->getUuid(),
                'conversation_kind' => $message->getConversation()->getKind()->value,
                'kind'              => $message->getKind(),
                'text'              => $message->getBody(),
                'sent_at'           => $message->getCreatedAt()->format(DATE_ATOM),
                'edited_at'         => $message->getEditedAt()?->format(DATE_ATOM),
            ], $messages),
            // 1.10: messages the user pinned (any author).
            'pins' => array_map(static fn (ChatMessage $message): array => [
                'conversation_uuid' => $message->getConversation()->getUuid(),
                'message_uuid'      => $message->getUuid(),
                'pinned_at'         => $message->getPinnedAt()?->format(DATE_ATOM),
            ], $pins),
        ];
    }

    /**
     * Erases the user's chat data: authored messages become author-less, text-less tombstones; reactions,
     * read cursors, settings and the user's own support tickets (with their messages) are deleted. The user's
     * mention rows go too, and every @mention of the user in other people's messages becomes a plain `@…`
     * (no name, no public id; {@see self::eraseMentions()}), so nothing links the erased account to those messages;
     * their HTML is rendered again and open clients get `message_updated`. The pinned-by, deleted-by, assignee and
     * resolved-by pointers to the user become null (an assigned ticket goes back to the queue), and a support bot line in
     * another user's ticket that names the user (`assigned`, `unassigned`, `status_*`, `topic_changed`, `continued`)
     * shows `…` instead. Call it before the host anonymizes the account: the bot lines are matched on the current
     * display name. 1.18: everything runs in one transaction (a failure rolls the whole erasure back) and
     * {@see ChatMentionsErasedEvent} is dispatched after it when a mention was rewritten or skipped. Bulk DQL updates
     * fire no Doctrine events: a host search index must re-index the rewritten messages (the event lists them).
     *
     * @param ChatUserInterface $user The user whose data is erased
     */
    public function erase(ChatUserInterface $user): void
    {
        $qb = $this->entityManager->createQueryBuilder(...);

        $this->entityManager->beginTransaction();
        try {
            $erasure = $this->scrubMentions($user, self::ERASED_MENTION);
            $this->keepModeratedState($user);
            foreach ([[ChatMessage::class, 'pinnedBy'], [ChatMessage::class, 'deletedBy'], [ChatConversation::class, 'assignee'], [ChatConversation::class, 'resolvedBy']] as [$entity, $field]) {
                $qb()->update($entity, 'x')->set('x.' . $field, ':none')->where('x.' . $field . ' = :user')
                    ->setParameter('none', null)->setParameter('user', $user)->getQuery()->execute();
            }
            $this->eraseBotNames($user);

            // The mention rows of the messages the user wrote (they become tombstones below).
            $authored = $qb()->select('m.id')->from(ChatMessage::class, 'm')->where('m.author = :user')
                ->setParameter('user', $user)->getQuery()->getSingleColumnResult();
            if ($authored !== []) {
                $qb()->delete(ChatMessageMention::class, 'n')->where('IDENTITY(n.message) IN (:ids)')
                    ->setParameter('ids', $authored)->getQuery()->execute();
            }
            $qb()->update(ChatMessage::class, 'm')
                ->set('m.body', ':empty')->set('m.bodyHtml', ':empty')
                ->set('m.author', ':none')->set('m.deletedAt', ':now')
                ->where('m.author = :user')
                ->setParameter('empty', '')->setParameter('none', null)->setParameter('now', $this->clock->now())
                ->setParameter('user', $user)
                ->getQuery()->execute();
            $qb()->delete(ChatReaction::class, 'r')->where('r.user = :user')->setParameter('user', $user)->getQuery()->execute();
            $qb()->delete(ChatParticipant::class, 'p')->where('p.user = :user')->setParameter('user', $user)->getQuery()->execute();
            $qb()->delete(ChatUserSettings::class, 's')->where('s.user = :user')->setParameter('user', $user)->getQuery()->execute();
            $qb()->delete(ChatQuickReply::class, 'q')->where('q.user = :user')->setParameter('user', $user)->getQuery()->execute();

            // Every support ticket of the user (and the intake draft), with its messages.
            foreach ($this->conversations->findBy(['supportOwner' => $user]) as $support) {
                $messageIds = $qb()->select('m.id')->from(ChatMessage::class, 'm')->where('m.conversation = :c')->setParameter('c', $support)->getQuery()->getSingleColumnResult();
                if ($messageIds !== []) {
                    $qb()->delete(ChatReaction::class, 'r')->where('IDENTITY(r.message) IN (:ids)')->setParameter('ids', $messageIds)->getQuery()->execute();
                }
                $qb()->delete(ChatMessage::class, 'm')->where('m.conversation = :c')->setParameter('c', $support)->getQuery()->execute();
                $qb()->delete(ChatParticipant::class, 'p')->where('p.conversation = :c')->setParameter('c', $support)->getQuery()->execute();
                $qb()->delete(ChatConversation::class, 'c')->where('c.id = :id')->setParameter('id', $support->getId())->getQuery()->execute();
            }
            $this->entityManager->commit();
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }

        $this->publishRewritten($erasure->messageIds);
        $this->dispatchErased($erasure);
    }

    /**
     * Privacy erasure of a user's mentions (1.15.2, public since 1.18, DATA-CHAT-MENTION-ERASE; same design as WikiBundle
     * 1.17 `WikiCommentService::eraseMentions()`): every `@[Name](publicId)` token of the user in a message that is not
     * deleted becomes `$label` in the body, the HTML is rendered again from the cleaned body (a token inside code is
     * literal text in the HTML and is scrubbed too), the message is not marked as edited, and the user's mention rows are
     * deleted (except those of skipped messages, so a second run finds them again). Open clients get `message_updated`
     * for the rewritten messages, and {@see ChatMentionsErasedEvent} is dispatched when a message was rewritten or skipped.
     * {@see self::erase()} runs it with the default label; call it alone to scrub the mentions of an account that was
     * erased before 1.15.2, or from a host eraser that keeps the rest of the chat data.
     *
     * The candidates (messages with a mention row of the user, or whose body contains `](publicId)`) are walked in
     * batches of {@see self::ERASE_BATCH} paged by id, each flushed and then released from the entity manager, all inside
     * one transaction: a failure rolls the whole erasure back, and a run that succeeded leaves nothing to do for a second
     * one. A flush writes every pending change of the entity manager (host changes included); only the messages the
     * lookup loaded itself are released (detached), the instances the caller already held stay managed.
     *
     * A message is skipped (left untouched, reported in {@see ChatMentionErasure::$skippedMessageIds} and in the event)
     * when its text cannot be scanned (invalid UTF-8 or another regex failure: nothing is ever written from a failed
     * scan) or when the rewrite would produce a mention that was not there before (text built around the token).
     *
     * @param ChatUserInterface $user The user whose mentions are erased
     * @param string $label What each mention becomes; it must not be able to render as a mention, alone or with the text
     *                      around it: not empty, valid UTF-8, no `[`, `]`, `(` or `)`, no `@` followed by a handle
     *                      character, no handle character first and no `@` last
     *
     * @throws InvalidArgumentException when the label could render as a mention
     *
     * @return ChatMentionErasure What was rewritten (countable) and what was skipped
     */
    public function eraseMentions(ChatUserInterface $user, string $label = self::ERASED_MENTION): ChatMentionErasure
    {
        $this->assertLabel($label);
        $this->entityManager->beginTransaction();
        try {
            $erasure = $this->scrubMentions($user, $label);
            $this->entityManager->commit();
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }
        $this->publishRewritten($erasure->messageIds);
        $this->dispatchErased($erasure);

        return $erasure;
    }

    /**
     * Refuses an erased-mention label that could render as a mention, alone or joined with the text around the token.
     *
     * @param string $label The label
     *
     * @throws InvalidArgumentException when it could
     */
    private function assertLabel(string $label): void
    {
        if (preg_match('//u', $label) !== 1) {
            throw new InvalidArgumentException('The erased-mention label is not valid UTF-8.');
        }
        if (preg_match(self::MENTION_LIKE_LABEL, $label) === 1) {
            throw new InvalidArgumentException(sprintf('The erased-mention label "%s" could render as a mention: use a label such as "%s" or "«deleted user»".', $label, self::ERASED_MENTION));
        }
    }

    /**
     * The batch walk of {@see self::eraseMentions()} (inside the caller's transaction): rewrites the candidates, deletes
     * the user's mention rows (but those of skipped messages).
     *
     * @param ChatUserInterface $user The user whose mentions are erased
     * @param string $label The checked label
     *
     * @return ChatMentionErasure What was rewritten and skipped
     */
    private function scrubMentions(ChatUserInterface $user, string $label): ChatMentionErasure
    {
        $publicId = $user->getChatPublicId();
        $token    = '~@\[[^\[\]\n]{1,80}\]\(' . preg_quote($publicId, '~') . '\)~u';
        $replace  = str_replace(['\\', '$'], ['\\\\', '\\$'], $label);
        /** @var array<int, bool> $rows id => rewritten (true) or skipped (false) */
        $rows  = [];
        $after = null;
        do {
            [$ids, $messages, $loaded] = $this->mentionBatch($user, $publicId, $after);
            $changed                   = false;
            foreach ($messages as $message) {
                $body = $message->getBody();
                // A regex failure (invalid UTF-8) is null: never write "" over someone's message.
                $scrubbed = preg_replace($token, $replace, $body);
                if ($scrubbed === null || !$this->addsNoMention($body, $scrubbed, $publicId)) {
                    $rows[(int) $message->getId()] ??= false;
                } elseif ($scrubbed !== $body) {
                    $message->redact($scrubbed, $this->markdown->render($scrubbed));
                    $rows[(int) $message->getId()] = true;
                    $changed                       = true;
                }
            }
            if ($changed) {
                $this->entityManager->flush();
            }
            foreach ($loaded as $message) {
                $this->entityManager->detach($message);
            }
            // The id page decides, not the loaded rows (a candidate deleted meanwhile must not end the walk).
            $after = $ids === [] ? null : $ids[array_key_last($ids)];
        } while (count($ids) === self::ERASE_BATCH);

        $skipped = array_keys(array_filter($rows, static fn (bool $rewritten): bool => !$rewritten));
        $delete  = $this->entityManager->createQueryBuilder()->delete(ChatMessageMention::class, 'n')
            ->where('n.user = :user')->setParameter('user', $user);
        if ($skipped !== []) {
            $delete->andWhere('IDENTITY(n.message) NOT IN (:skipped)')->setParameter('skipped', $skipped);
        }
        $delete->getQuery()->execute();

        return new ChatMentionErasure($publicId, array_keys(array_filter($rows)), $skipped);
    }

    /**
     * One page of candidates of {@see self::scrubMentions()}: messages not deleted with a mention row of the user, or
     * whose body contains `](publicId)` (a superset; the token regex decides), ordered by id, after `$after`.
     *
     * @param ChatUserInterface $user The user whose mentions are erased
     * @param string $publicId The user's public id
     * @param int|null $after Only ids after this one
     *
     * @return array{0: list<int>, 1: list<ChatMessage>, 2: list<ChatMessage>} The page's ids, the messages that still
     *                                                                         exist, and those the lookup loaded itself
     */
    private function mentionBatch(ChatUserInterface $user, string $publicId, ?int $after): array
    {
        $mentioned = $this->entityManager->createQueryBuilder()->select('IDENTITY(n.message)')
            ->from(ChatMessageMention::class, 'n')->where('n.user = :user')->getDQL();
        $query = $this->entityManager->createQueryBuilder()->select('m.id')->from(ChatMessage::class, 'm')
            ->where('m.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(self::ERASE_BATCH);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $publicId) === 1) {
            $query->andWhere('(m.id IN (' . $mentioned . ') OR m.body LIKE :needle ESCAPE \'!\')')
                ->setParameter('needle', '%](' . strtr($publicId, ['!' => '!!', '_' => '!_', '%' => '!%']) . ')%');
        } else {
            $query->andWhere('m.id IN (' . $mentioned . ')');
        }
        if ($after !== null) {
            $query->andWhere('m.id > :after')->setParameter('after', $after);
        }
        $ids = array_values(array_map(intval(...), $query->getQuery()->getSingleColumnResult()));
        if ($ids === []) {
            return [[], [], []];
        }
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $managed    = [];
        foreach ($ids as $id) {
            if ($unitOfWork->tryGetById($id, ChatMessage::class) !== false) {
                $managed[$id] = true;
            }
        }
        /** @var list<ChatMessage> $messages */
        $messages = $this->entityManager->createQueryBuilder()->select('m')->from(ChatMessage::class, 'm')
            ->where('m.id IN (:ids)')->setParameter('ids', $ids)->orderBy('m.id', 'ASC')
            ->getQuery()->getResult();
        $loaded = array_values(array_filter($messages, static fn (ChatMessage $message): bool => !isset($managed[(int) $message->getId()])));

        return [$ids, $messages, $loaded];
    }

    /**
     * Whether the scrubbed body holds no mention the original did not hold (text built around a token, such as
     * `@[x @[Ana](id)](other)`, would otherwise turn into a new mention), and none of the erased user.
     *
     * @param string $before The original body
     * @param string $after The scrubbed body
     * @param string $publicId The erased user's public id
     */
    private function addsNoMention(string $before, string $after, string $publicId): bool
    {
        $tokens = static function (string $text): array {
            preg_match_all(ChatMentions::TOKEN, $text, $matches);

            return array_count_values($matches[0]);
        };
        $old = $tokens($before);
        foreach ($tokens($after) as $mention => $count) {
            if (str_ends_with($mention, '](' . $publicId . ')') || $count > ($old[$mention] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /** Rolls the erasure back; a rollback that fails too never hides the cause. */
    private function rollBack(): void
    {
        try {
            $this->entityManager->rollback();
        } catch (Throwable) {
        }
    }

    /**
     * Dispatches {@see ChatMentionsErasedEvent} when a message was rewritten or skipped.
     *
     * @param ChatMentionErasure $erasure What was rewritten and skipped
     */
    private function dispatchErased(ChatMentionErasure $erasure): void
    {
        if ($erasure->messageIds !== [] || $erasure->skippedMessageIds !== []) {
            $this->events?->dispatch(new ChatMentionsErasedEvent($erasure->publicId, $erasure->messageIds, $erasure->skippedMessageIds));
        }
    }

    /**
     * Messages of other people the user removed (as a moderator) keep their moderated state once the deleted-by
     * pointer is nulled: `meta.moderated = true` (DP-L4). It also covers removals made before 1.15.3, when the flag
     * was not stored yet. Nothing about the moderator is kept.
     *
     * @param ChatUserInterface $user The user whose data is erased
     */
    private function keepModeratedState(ChatUserInterface $user): void
    {
        /** @var iterable<array{id: int, meta: array<string, mixed>|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m.id', 'm.meta')->from(ChatMessage::class, 'm')
            ->where('m.deletedBy = :user')->andWhere('m.author IS NULL OR m.author <> :user')
            ->setParameter('user', $user)
            ->getQuery()->toIterable([], AbstractQuery::HYDRATE_ARRAY);
        foreach ($rows as $row) {
            $meta = $row['meta'] ?? [];
            if (($meta['moderated'] ?? false) === true) {
                continue;
            }
            $meta['moderated'] = true;
            $this->entityManager->createQueryBuilder()->update(ChatMessage::class, 'm')
                ->set('m.meta', ':meta')->where('m.id = :id')
                ->setParameter('meta', $meta, Types::JSON)->setParameter('id', $row['id'])
                ->getQuery()->execute();
        }
    }

    /**
     * Support bot lines copy display names into their translation parameters (`assigned`: `name`; `unassigned`,
     * `status_*`, `topic_changed`, `continued`: `by`). Every parameter equal to the user's display name becomes `…`
     * (a namesake is over-scrubbed, which is harmless).
     *
     * @param ChatUserInterface $user The user whose data is erased
     */
    private function eraseBotNames(ChatUserInterface $user): void
    {
        $name = $user->getChatDisplayName();
        if ($name === '') {
            return;
        }
        /** @var iterable<array{id: int, meta: array<string, mixed>|null}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('m.id', 'm.meta')->from(ChatMessage::class, 'm')
            ->where('m.kind = :bot')->setParameter('bot', ChatMessage::KIND_BOT)
            ->getQuery()->toIterable([], AbstractQuery::HYDRATE_ARRAY);
        foreach ($rows as $row) {
            $meta   = $row['meta'] ?? [];
            $params = $meta['params'] ?? null;
            if (!is_array($params) || !in_array($name, $params, true)) {
                continue;
            }
            $meta['params'] = array_map(static fn (mixed $value): mixed => $value === $name ? '…' : $value, $params);
            $this->entityManager->createQueryBuilder()->update(ChatMessage::class, 'm')
                ->set('m.meta', ':meta')->where('m.id = :id')
                ->setParameter('meta', $meta, Types::JSON)->setParameter('id', $row['id'])
                ->getQuery()->execute();
        }
    }

    /**
     * Sends `message_updated` for the rewritten messages that still exist, so open clients drop the old HTML.
     *
     * @param list<int> $ids Ids of the rewritten messages
     */
    private function publishRewritten(array $ids): void
    {
        if ($ids === [] || !$this->notifier instanceof ChatNotifier) {
            return;
        }
        /** @var list<ChatMessage> $messages */
        $messages = $this->entityManager->createQueryBuilder()
            ->select('m')->from(ChatMessage::class, 'm')->where('m.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        $reactions = $this->reactions?->summaryFor($ids) ?? [];
        foreach ($messages as $message) {
            $this->notifier->messageUpdated($message, $reactions[(int) $message->getId()] ?? []);
        }
    }
}

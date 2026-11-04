<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Privacy;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

use function array_map;
use function in_array;
use function is_array;
use function preg_quote;
use function preg_replace;

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
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatUserSettingsRepository $settings,
        private ChatConversationRepository $conversations,
        private ClockInterface $clock = new Clock(),
        private ?ChatNotifier $notifier = null,
        private ?ChatReactionRepository $reactions = null,
        private ChatMarkdownRenderer $markdown = new ChatMarkdownRenderer(),
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
     * (no name, no public id), so nothing links the erased account to those messages; their HTML is rendered
     * again and open clients get `message_updated`. The pinned-by, deleted-by, assignee and resolved-by pointers to
     * the user become null (an assigned ticket goes back to the queue), and a support bot line in another user's
     * ticket that names the user (`assigned`, `unassigned`, `status_*`, `topic_changed`, `continued`) shows `…`
     * instead. Call it before the host anonymizes the account: the bot lines are matched on the current display
     * name. Bulk DQL updates fire no Doctrine events: a host search index must re-index the rewritten messages.
     *
     * @param ChatUserInterface $user The user whose data is erased
     */
    public function erase(ChatUserInterface $user): void
    {
        $qb = $this->entityManager->createQueryBuilder(...);

        $rewritten = $this->eraseMentions($user);
        $this->keepModeratedState($user);
        foreach ([[ChatMessage::class, 'pinnedBy'], [ChatMessage::class, 'deletedBy'], [ChatConversation::class, 'assignee'], [ChatConversation::class, 'resolvedBy']] as [$entity, $field]) {
            $qb()->update($entity, 'x')->set('x.' . $field, ':none')->where('x.' . $field . ' = :user')
                ->setParameter('none', null)->setParameter('user', $user)->getQuery()->execute();
        }
        $this->eraseBotNames($user);

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

        $this->publishRewritten($rewritten);
    }

    /**
     * Removes the user from @mentions: their name and public id leave the body and HTML of every message that
     * mentions them, and their mention rows (and the rows of the messages they wrote, which become tombstones) are
     * deleted. The HTML is rendered again from the cleaned body (a token inside code is literal text in the HTML).
     * Runs before the tombstone update, while the authored messages still carry the author.
     *
     * @param ChatUserInterface $user The user whose data is erased
     *
     * @return list<int> Ids of the rewritten messages
     */
    private function eraseMentions(ChatUserInterface $user): array
    {
        $text = '~@\\[[^\\[\\]\\n]{1,80}\\]\\(' . preg_quote($user->getChatPublicId(), '~') . '\\)~u';

        /** @var list<ChatMessage> $messages */
        $messages = $this->entityManager->createQueryBuilder()
            ->select('m')->from(ChatMessage::class, 'm')
            ->innerJoin(ChatMessageMention::class, 'n', 'WITH', 'n.message = m')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->getQuery()->getResult();
        $rewritten = [];
        foreach ($messages as $message) {
            $body = (string) preg_replace($text, '@…', $message->getBody());
            $this->entityManager->createQueryBuilder()->update(ChatMessage::class, 'm')
                ->set('m.body', ':body')->set('m.bodyHtml', ':html')
                ->where('m.id = :id')
                ->setParameter('body', $body)
                ->setParameter('html', $this->markdown->render($body))
                ->setParameter('id', $message->getId())
                ->getQuery()->execute();
            $rewritten[] = (int) $message->getId();
        }

        $this->entityManager->createQueryBuilder()->delete(ChatMessageMention::class, 'n')
            ->where('n.user = :user')
            ->setParameter('user', $user)->getQuery()->execute();
        $authored = $this->entityManager->createQueryBuilder()
            ->select('m.id')->from(ChatMessage::class, 'm')->where('m.author = :user')
            ->setParameter('user', $user)->getQuery()->getSingleColumnResult();
        if ($authored !== []) {
            $this->entityManager->createQueryBuilder()->delete(ChatMessageMention::class, 'n')
                ->where('IDENTITY(n.message) IN (:ids)')
                ->setParameter('ids', $authored)->getQuery()->execute();
        }

        return $rewritten;
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

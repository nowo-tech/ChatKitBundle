<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Privacy;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

use const DATE_ATOM;

/**
 * Personal data hooks for the application's export / erasure flows (GDPR). Export: the user's own messages
 * and chat settings (other people's messages are not the user's personal data). Erasure: authored messages
 * become tombstones with no author and no text (the thread keeps its shape for the others), reactions, read
 * cursors, settings and the user's own support tickets are deleted.
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
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatUserSettingsRepository $settings,
        private ChatConversationRepository $conversations,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * Exports the user's chat settings and their own non-deleted messages, oldest first.
     *
     * @param ChatUserInterface $user The user whose data is exported
     *
     * @return array{settings: array<string, bool|int|string|null>, messages: list<array<string, string|null>>, pins: list<array<string, string|null>>} Settings, authored messages and the pins the user made
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
            ],
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
     * read cursors, settings and the user's own support tickets (with their messages) are deleted.
     *
     * @param ChatUserInterface $user The user whose data is erased
     */
    public function erase(ChatUserInterface $user): void
    {
        $qb = $this->entityManager->createQueryBuilder(...);

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
    }
}

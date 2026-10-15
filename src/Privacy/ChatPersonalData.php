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
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;

/**
 * Personal data hooks for the application's export / erasure flows (GDPR). Export: the user's own messages
 * and chat settings (other people's messages are not the user's personal data). Erasure: authored messages
 * become tombstones with no author and no text (the thread keeps its shape for the others), reactions, read
 * cursors, settings and the user's own support thread are deleted.
 */
final readonly class ChatPersonalData
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatUserSettingsRepository $settings,
    ) {
    }

    /**
     * @return array{settings: array<string, bool|string>, messages: list<array<string, string|null>>}
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

        return [
            'settings' => [
                'sound' => $settings->getSound(),
                'push' => $settings->isPushEnabled(),
                'buzzes' => $settings->isBuzzEnabled(),
                'show_online' => $settings->isPresenceVisible(),
            ],
            'messages' => array_map(static fn (ChatMessage $message): array => [
                'conversation_uuid' => $message->getConversation()->getUuid(),
                'conversation_kind' => $message->getConversation()->getKind()->value,
                'kind' => $message->getKind(),
                'text' => $message->getBody(),
                'sent_at' => $message->getCreatedAt()->format(\DATE_ATOM),
                'edited_at' => $message->getEditedAt()?->format(\DATE_ATOM),
            ], $messages),
        ];
    }

    public function erase(ChatUserInterface $user): void
    {
        $qb = $this->entityManager->createQueryBuilder(...);

        $qb()->update(ChatMessage::class, 'm')
            ->set('m.body', ':empty')->set('m.bodyHtml', ':empty')
            ->set('m.author', ':none')->set('m.deletedAt', ':now')
            ->where('m.author = :user')
            ->setParameter('empty', '')->setParameter('none', null)->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()->execute();
        $qb()->delete(ChatReaction::class, 'r')->where('r.user = :user')->setParameter('user', $user)->getQuery()->execute();
        $qb()->delete(ChatParticipant::class, 'p')->where('p.user = :user')->setParameter('user', $user)->getQuery()->execute();
        $qb()->delete(ChatUserSettings::class, 's')->where('s.user = :user')->setParameter('user', $user)->getQuery()->execute();

        $support = $this->entityManager->getRepository(ChatConversation::class)->findOneBy(['supportOwner' => $user]);
        if ($support instanceof ChatConversation) {
            $messageIds = $qb()->select('m.id')->from(ChatMessage::class, 'm')->where('m.conversation = :c')->setParameter('c', $support)->getQuery()->getSingleColumnResult();
            if ([] !== $messageIds) {
                $qb()->delete(ChatReaction::class, 'r')->where('IDENTITY(r.message) IN (:ids)')->setParameter('ids', $messageIds)->getQuery()->execute();
            }
            $qb()->delete(ChatMessage::class, 'm')->where('m.conversation = :c')->setParameter('c', $support)->getQuery()->execute();
            $qb()->delete(ChatParticipant::class, 'p')->where('p.conversation = :c')->setParameter('c', $support)->getQuery()->execute();
            $qb()->delete(ChatConversation::class, 'c')->where('c.id = :id')->setParameter('id', $support->getId())->getQuery()->execute();
        }
    }
}

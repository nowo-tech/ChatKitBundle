<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatParticipant;

/**
 * 1.27: read-only snapshot of a participant row (one user in one conversation) for the framework-free application port
 * (ChatMessagingInterface): public ids and scalars only, no Doctrine entity and no user object. Taken when it is built.
 */
final readonly class ChatParticipantView
{
    /**
     * @param string $conversationUuid Public uuid of the conversation
     * @param string $userPublicId The user's public id
     * @param int|null $lastReadMessageId The read cursor; null when nothing was read yet
     * @param DateTimeImmutable|null $mutedUntil Mute end; null when not muted
     * @param DateTimeImmutable|null $archivedAt When the user closed the conversation in their list; null when open
     * @param DateTimeImmutable $joinedAt When the row was created
     */
    public function __construct(
        public string $conversationUuid,
        public string $userPublicId,
        public ?int $lastReadMessageId,
        public ?DateTimeImmutable $mutedUntil,
        public ?DateTimeImmutable $archivedAt,
        public DateTimeImmutable $joinedAt,
    ) {
    }

    /**
     * Snapshot of a participant row.
     *
     * @param ChatParticipant $participant The row
     *
     * @return self The view
     */
    public static function of(ChatParticipant $participant): self
    {
        return new self(
            $participant->getConversation()->getUuid(),
            $participant->getUser()->getChatPublicId(),
            $participant->getLastReadMessageId(),
            $participant->getMutedUntil(),
            $participant->getArchivedAt(),
            $participant->getJoinedAt(),
        );
    }
}

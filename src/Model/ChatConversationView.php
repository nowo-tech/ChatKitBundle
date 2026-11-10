<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;

/**
 * 1.24: read-only snapshot of a conversation for event listeners and application ports (DDD hosts): scalars and public
 * ids only, no Doctrine entity and no user object. Taken when it is built; it never changes afterwards.
 */
final readonly class ChatConversationView
{
    /**
     * @param int $id Internal id (message cursors, the bundle's own tables)
     * @param string $uuid Public uuid (API, URLs)
     * @param string $kind `direct`, `channel` or `support`
     * @param string|null $channelKey The application channel key; null for other kinds
     * @param string|null $supportOwnerPublicId Support threads: the requester's public id; null otherwise or once gone
     * @param string|null $assigneePublicId Support tickets: the assigned agent's public id, if any
     * @param string|null $supportStatus Support threads: `open`, `waiting`, `resolved` or `closed`
     * @param int|null $ticketNumber Support tickets: the number; null for drafts and other kinds
     * @param string|null $ticketTopic Support tickets: the topic key
     * @param DateTimeImmutable $createdAt Creation time
     * @param DateTimeImmutable $lastMessageAt Last activity
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public string $kind,
        public ?string $channelKey,
        public ?string $supportOwnerPublicId,
        public ?string $assigneePublicId,
        public ?string $supportStatus,
        public ?int $ticketNumber,
        public ?string $ticketTopic,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastMessageAt,
    ) {
    }

    /**
     * Snapshot of a conversation.
     *
     * @param ChatConversation $conversation The conversation
     *
     * @return self The view
     */
    public static function of(ChatConversation $conversation): self
    {
        return new self(
            (int) $conversation->getId(),
            $conversation->getUuid(),
            $conversation->getKind()->value,
            $conversation->getChannelKey(),
            $conversation->getSupportOwner()?->getChatPublicId(),
            $conversation->getAssignee()?->getChatPublicId(),
            $conversation->getSupportStatus()?->value,
            $conversation->getTicketNumber(),
            $conversation->getTicketTopic(),
            $conversation->getCreatedAt(),
            $conversation->getLastMessageAt(),
        );
    }
}

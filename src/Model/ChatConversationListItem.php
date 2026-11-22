<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use DateTimeImmutable;

/**
 * 2.2: one row of a user's conversation list (ChatQueryInterface::conversationsFor()): the conversation's view plus what
 * is the user's own about it. Scalars, public ids and views only; never an entity. Taken when it is built.
 */
final readonly class ChatConversationListItem
{
    /**
     * @param ChatConversationView $conversation The conversation
     * @param int $unread Messages of others after the user's read cursor (thread replies and deleted messages not counted)
     * @param bool $archived Whether the user closed it (it is back when a message arrives, unless muted or a resolved ticket)
     * @param DateTimeImmutable|null $mutedUntil End of the user's mute; null when not muted
     * @param ChatMessageView|null $lastMessage The newest message of the main timeline; null for an empty conversation
     */
    public function __construct(
        public ChatConversationView $conversation,
        public int $unread,
        public bool $archived,
        public ?DateTimeImmutable $mutedUntil,
        public ?ChatMessageView $lastMessage,
    ) {
    }
}

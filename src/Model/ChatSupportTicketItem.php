<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 2.2: one row of the support inbox (ChatSupportInboxInterface::tickets()): the support thread's view plus what the agent
 * sees about it. Scalars, public ids and views only; never an entity. Taken when it is built.
 */
final readonly class ChatSupportTicketItem
{
    /**
     * @param ChatConversationView $ticket The support thread (its requester: `supportOwnerPublicId`)
     * @param int $unread Messages of others after the agent's read cursor
     * @param int $requesterTickets Number of tickets of its requester (this one included); 0 once the requester is gone
     * @param ChatMessageView|null $lastMessage The newest message of the main timeline
     */
    public function __construct(
        public ChatConversationView $ticket,
        public int $unread,
        public int $requesterTickets,
        public ?ChatMessageView $lastMessage,
    ) {
    }
}

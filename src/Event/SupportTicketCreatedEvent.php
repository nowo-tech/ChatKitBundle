<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * A user chose a topic and a new support ticket opened (number, topic and requester are on the conversation).
 */
final readonly class SupportTicketCreatedEvent
{
    /**
     * Builds the event.
     *
     * @param ChatConversation $ticket The new ticket
     * @param ChatUserInterface $requester The user who opened it
     */
    public function __construct(
        public ChatConversation $ticket,
        public ChatUserInterface $requester,
    ) {
    }

    /**
     * 1.24: the ticket as a read-only snapshot (scalars and public ids), for listeners that must not keep the entity.
     *
     * @return ChatConversationView The snapshot
     */
    public function ticketView(): ChatConversationView
    {
        return ChatConversationView::of($this->ticket);
    }

    /**
     * 1.24: public id of `requester`.
     *
     * @return string The public id
     */
    public function requesterPublicId(): string
    {
        return $this->requester->getChatPublicId();
    }
}

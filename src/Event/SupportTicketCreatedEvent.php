<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
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
}

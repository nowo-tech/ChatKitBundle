<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * A resolved, waiting or idle ticket went back to open: the requester continued it, wrote in it or pressed Reopen,
 * or an agent reopened it.
 */
final readonly class SupportTicketReopenedEvent
{
    /**
     * Builds the event.
     *
     * @param ChatConversation $ticket The reopened ticket
     * @param ChatUserInterface $by Who reopened it (the requester or an agent)
     */
    public function __construct(
        public ChatConversation $ticket,
        public ChatUserInterface $by,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * The status of a ticket changed on purpose (an agent's action, a reopen). The automatic open ↔ waiting switch on
 * each reply does not dispatch it.
 */
final readonly class SupportTicketStatusChangedEvent
{
    /**
     * Builds the event.
     *
     * @param ChatConversation $ticket The ticket (its new status is on the entity)
     * @param SupportStatus $previous The status before the change
     * @param ChatUserInterface $by Who changed it
     */
    public function __construct(
        public ChatConversation $ticket,
        public SupportStatus $previous,
        public ChatUserInterface $by,
    ) {
    }
}

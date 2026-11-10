<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Model\ChatConversationView;
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
     * 1.24: public id of `by`.
     *
     * @return string The public id
     */
    public function byPublicId(): string
    {
        return $this->by->getChatPublicId();
    }
}

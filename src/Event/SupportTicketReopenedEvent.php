<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatConversationView;
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

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
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatConversation $ticket,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
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

    /**
     * 2.0: the ticket (conversation) uuid.
     */
    public function ticketUuid(): string
    {
        return $this->ticket->getUuid();
    }

    /**
     * 2.0: The user who reopened. The key the chat stores for the user: the host user id as a string (`getReference()` / compare with your own id).
     */
    public function byReference(): string
    {
        return EventRefs::user($this->by);
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function ticketId(): int
    {
        return EventRefs::id($this->ticket->getId());
    }
}

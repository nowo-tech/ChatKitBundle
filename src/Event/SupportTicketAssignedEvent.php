<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * A ticket was assigned to an agent (or unassigned: `assignee` null), by an agent or by the topic's default assignee
 * when the ticket opened (`by` is then the requester).
 */
final readonly class SupportTicketAssignedEvent
{
    /**
     * Builds the event.
     *
     * @param ChatConversation $ticket The ticket
     * @param ChatUserInterface|null $assignee The new assignee; null when unassigned
     * @param ChatUserInterface $by Who assigned it
     */
    public function __construct(
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatConversation $ticket,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
        public ?ChatUserInterface $assignee,
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
     * 1.24: public id of `assignee` (null when none).
     *
     * @return string|null The public id
     */
    public function assigneePublicId(): ?string
    {
        return $this->assignee?->getChatPublicId();
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
     * 2.0: The new assignee. The key the chat stores for the user (the host user id as a string); null for none.
     */
    public function assigneeReference(): ?string
    {
        return EventRefs::optional($this->assignee);
    }

    /**
     * 2.0: The user who assigned. The key the chat stores for the user: the host user id as a string (`getReference()` / compare with your own id).
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

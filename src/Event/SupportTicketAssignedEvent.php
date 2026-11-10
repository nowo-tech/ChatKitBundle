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
        public ChatConversation $ticket,
        public ?ChatUserInterface $assignee,
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
}

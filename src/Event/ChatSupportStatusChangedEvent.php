<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * A support thread was resolved by an agent (`by`) or reopened by its owner's new message (`by` = the owner) or by an agent.
 */
final readonly class ChatSupportStatusChangedEvent
{
    /**
     * Builds the event from the support thread and who changed its status.
     *
     * @param ChatConversation $conversation The support thread (its new status is on the entity)
     * @param ChatUserInterface|null $by The agent or owner who changed it; null when unknown
     */
    public function __construct(
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatConversation $conversation,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
        public ?ChatUserInterface $by,
    ) {
    }

    /**
     * 1.24: the support thread as a read-only snapshot (scalars and public ids), for listeners that must not keep the entity.
     *
     * @return ChatConversationView The snapshot
     */
    public function conversationView(): ChatConversationView
    {
        return ChatConversationView::of($this->conversation);
    }

    /**
     * 1.24: public id of `by` (null when none).
     *
     * @return string|null The public id
     */
    public function byPublicId(): ?string
    {
        return $this->by?->getChatPublicId();
    }

    /**
     * 2.0: the conversation uuid (no entity needed).
     */
    public function conversationUuid(): string
    {
        return $this->conversation->getUuid();
    }

    /**
     * 2.0: The agent or owner who changed the status. The key the chat stores for the user (the host user id as a string); null for none.
     */
    public function byReference(): ?string
    {
        return EventRefs::optional($this->by);
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function conversationId(): int
    {
        return EventRefs::id($this->conversation->getId());
    }
}

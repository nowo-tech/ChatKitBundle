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
        public ChatConversation $conversation,
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
}

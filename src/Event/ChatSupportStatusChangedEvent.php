<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
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
}

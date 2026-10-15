<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * A support thread was resolved by an agent (`by`) or reopened by its owner's new message (`by` = the owner).
 */
final readonly class ChatSupportStatusChangedEvent
{
    public function __construct(
        public ChatConversation $conversation,
        public ?ChatUserInterface $by,
    ) {
    }
}

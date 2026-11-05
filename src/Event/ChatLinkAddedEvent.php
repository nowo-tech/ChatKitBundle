<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.19: a link was pinned to a conversation (after the flush, before the `links` broadcast).
 */
final readonly class ChatLinkAddedEvent
{
    /**
     * @param ChatConversationLink $link The new pinned link (its conversation: `$link->getConversation()`)
     * @param ChatUserInterface $by Who added it
     */
    public function __construct(
        public ChatConversationLink $link,
        public ChatUserInterface $by,
    ) {
    }
}

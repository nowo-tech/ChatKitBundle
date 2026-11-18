<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;

/**
 * 1.36: a reply was posted in a thread, dispatched after the commit (besides ChatMessageSentEvent).
 */
final readonly class ChatThreadReplyEvent
{
    /**
     * @param ChatMessage $root The root of the thread
     * @param ChatMessage $reply The reply
     */
    public function __construct(
        public ChatMessage $root,
        public ChatMessage $reply,
    ) {
    }

    /**
     * The reply as a read-only snapshot.
     */
    public function replyView(): ChatMessageView
    {
        return ChatMessageView::of($this->reply);
    }

    /**
     * Public uuid of the root.
     */
    public function rootUuid(): string
    {
        return $this->root->getUuid();
    }
}

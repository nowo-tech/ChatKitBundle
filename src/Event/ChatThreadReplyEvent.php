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
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatMessage $root,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
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

    /**
     * 2.0: the reply uuid.
     */
    public function replyUuid(): string
    {
        return $this->reply->getUuid();
    }

    /**
     * 2.0: the conversation uuid of the thread.
     */
    public function conversationUuid(): string
    {
        return $this->root->getConversation()->getUuid();
    }

    /**
     * 2.0: the key of the reply author (the host user id as a string); null for a deleted account.
     */
    public function replyAuthorReference(): ?string
    {
        return $this->reply->getAuthorReference();
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function rootId(): int
    {
        return EventRefs::id($this->root->getId());
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function replyId(): int
    {
        return EventRefs::id($this->reply->getId());
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function conversationId(): int
    {
        return EventRefs::id($this->root->getConversation()->getId());
    }
}

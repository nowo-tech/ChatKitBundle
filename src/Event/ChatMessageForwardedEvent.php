<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.36: a message was forwarded to one conversation, dispatched after the commit (once per target).
 */
final readonly class ChatMessageForwardedEvent
{
    /**
     * @param ChatMessage $source The forwarded message
     * @param ChatMessage $message The new message in the target conversation
     * @param ChatUserInterface $actor Who forwarded it
     */
    public function __construct(
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatMessage $source,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatMessage $message,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
        public ChatUserInterface $actor,
    ) {
    }

    /**
     * The new message as a read-only snapshot.
     */
    public function messageView(): ChatMessageView
    {
        return ChatMessageView::of($this->message);
    }

    /**
     * Public uuid of the forwarded message.
     */
    public function sourceUuid(): string
    {
        return $this->source->getUuid();
    }

    /**
     * Public id of the user who forwarded it.
     */
    public function actorPublicId(): string
    {
        return $this->actor->getChatPublicId();
    }

    /**
     * 2.0: the message uuid (no entity needed).
     */
    public function messageUuid(): string
    {
        return $this->message->getUuid();
    }

    /**
     * 2.0: the conversation uuid of the message (no entity needed).
     */
    public function conversationUuid(): string
    {
        return $this->message->getConversation()->getUuid();
    }

    /**
     * 2.0: the conversation uuid of the source message.
     */
    public function sourceConversationUuid(): string
    {
        return $this->source->getConversation()->getUuid();
    }

    /**
     * 2.0: The user who forwarded. The key the chat stores for the user: the host user id as a string (`getReference()` / compare with your own id).
     */
    public function actorReference(): string
    {
        return EventRefs::user($this->actor);
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function sourceId(): int
    {
        return EventRefs::id($this->source->getId());
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function messageId(): int
    {
        return EventRefs::id($this->message->getId());
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function conversationId(): int
    {
        return EventRefs::id($this->message->getConversation()->getId());
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function sourceConversationId(): int
    {
        return EventRefs::id($this->source->getConversation()->getId());
    }
}

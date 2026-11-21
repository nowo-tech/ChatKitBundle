<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;

/**
 * A message (or buzz) was stored and published. Use it for side effects of your own (counters, audit, bots).
 */
final readonly class ChatMessageSentEvent
{
    /**
     * Builds the event from the stored message.
     *
     * @param ChatMessage $message The message (or buzz) that was sent
     */
    public function __construct(
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatMessage $message,
    ) {
    }

    /**
     * 1.24: the message as a read-only snapshot (scalars and public ids), for listeners that must not keep the entity.
     *
     * @return ChatMessageView The snapshot
     */
    public function messageView(): ChatMessageView
    {
        return ChatMessageView::of($this->message);
    }

    /**
     * 1.36: public uuid of the thread root when the message is a thread reply.
     *
     * @return string|null The root's uuid; null for a message of the main timeline
     */
    public function threadRootUuid(): ?string
    {
        return $this->message->getThreadRoot()?->getUuid();
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
     * 2.0: the key of the message author (the host user id as a string); null for a system line or a deleted account.
     */
    public function authorReference(): ?string
    {
        return $this->message->getAuthorReference();
    }

    /**
     * 2.0: the author public id; null for a system line or a deleted account.
     */
    public function authorPublicId(): ?string
    {
        return $this->message->getAuthor()?->getChatPublicId();
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
}

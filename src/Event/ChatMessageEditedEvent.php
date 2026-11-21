<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;

/**
 * 1.36: a message was edited, dispatched after the commit.
 */
final readonly class ChatMessageEditedEvent
{
    /**
     * @param ChatMessage $message The edited message
     * @param int|null $revisionId Id of the stored previous text (`message_menu.edit_history`); null when none is kept
     */
    public function __construct(
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatMessage $message,
        public ?int $revisionId = null,
    ) {
    }

    /**
     * The message as a read-only snapshot.
     */
    public function messageView(): ChatMessageView
    {
        return ChatMessageView::of($this->message);
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
     * 2.0: the key of the message author (the host user id as a string); null once the account is deleted.
     */
    public function authorReference(): ?string
    {
        return $this->message->getAuthorReference();
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

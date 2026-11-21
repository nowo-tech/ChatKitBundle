<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.35: a user saved a message ("Save for later"), dispatched after the commit.
 */
final readonly class ChatMessageSavedEvent
{
    /**
     * @param ChatMessage $message The message
     * @param ChatUserInterface $user Who did it
     */
    public function __construct(
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatMessage $message,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
        public ChatUserInterface $user,
    ) {
    }

    /**
     * The message as a read-only snapshot (scalars and public ids), for listeners that must not keep the entity.
     *
     * @return ChatMessageView The snapshot
     */
    public function messageView(): ChatMessageView
    {
        return ChatMessageView::of($this->message);
    }

    /**
     * Public id of the user.
     *
     * @return string The public id
     */
    public function userPublicId(): string
    {
        return $this->user->getChatPublicId();
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
     * 2.0: The user who saved the message. The key the chat stores for the user: the host user id as a string (`getReference()` / compare with your own id).
     */
    public function userReference(): string
    {
        return EventRefs::user($this->user);
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

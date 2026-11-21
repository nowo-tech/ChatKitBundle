<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * A message was deleted; `moderated` when someone other than the author (a support agent) removed it — audit it.
 */
final readonly class ChatMessageDeletedEvent
{
    /**
     * Builds the event from the deleted message, who deleted it and whether it was a moderation.
     *
     * @param ChatMessage $message The deleted message (now a tombstone)
     * @param ChatUserInterface $by The user who deleted it
     * @param bool $moderated True when someone other than the author removed it
     */
    public function __construct(
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatMessage $message,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
        public ChatUserInterface $by,
        public bool $moderated,
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
     * 1.24: public id of who deleted it.
     *
     * @return string The public id
     */
    public function byPublicId(): string
    {
        return $this->by->getChatPublicId();
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
     * 2.0: The user who deleted the message. The key the chat stores for the user: the host user id as a string (`getReference()` / compare with your own id).
     */
    public function byReference(): string
    {
        return EventRefs::user($this->by);
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

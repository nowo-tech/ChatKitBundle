<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.35: "Mark unread from here" moved a user's read marker back, dispatched after the commit. The receipts other
 * people see did not move.
 */
final readonly class ChatConversationMarkedUnreadEvent
{
    /**
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $user Whose marker moved
     * @param string $fromMessageUuid The first message unread again
     * @param int|null $readUpTo The read marker now (null: nothing read)
     */
    public function __construct(
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatConversation $conversation,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
        public ChatUserInterface $user,
        public string $fromMessageUuid,
        public ?int $readUpTo,
    ) {
    }

    /**
     * Uuid of the conversation.
     *
     * @return string The uuid
     */
    public function conversationUuid(): string
    {
        return $this->conversation->getUuid();
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
     * 2.0: The user. The key the chat stores for the user: the host user id as a string (`getReference()` / compare with your own id).
     */
    public function userReference(): string
    {
        return EventRefs::user($this->user);
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function conversationId(): int
    {
        return EventRefs::id($this->conversation->getId());
    }
}

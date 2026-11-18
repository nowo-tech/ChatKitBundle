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
        public ChatConversation $conversation,
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
}

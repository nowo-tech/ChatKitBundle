<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.35: a user removed from their saved messages a message ("Save for later"), dispatched after the commit.
 */
final readonly class ChatMessageUnsavedEvent
{
    /**
     * @param ChatMessage $message The message
     * @param ChatUserInterface $user Who did it
     */
    public function __construct(
        public ChatMessage $message,
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
}

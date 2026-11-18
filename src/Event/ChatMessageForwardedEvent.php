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
        public ChatMessage $source,
        public ChatMessage $message,
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
}

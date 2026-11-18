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
}

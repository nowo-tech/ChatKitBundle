<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;

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
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;

/**
 * 1.36: one message ChatService::post() is to write — in the main timeline, as a thread reply, or as a forward. Built
 * per call and never stored (worker mode).
 *
 * @internal
 */
final readonly class ChatDraft
{
    /**
     * @param ChatConversation $conversation Where it goes
     * @param string $body Raw Markdown
     * @param ChatMessage|null $threadRoot The thread it replies in (a reply to a reply goes to its root); null: main timeline
     * @param ChatMessage|null $forwardOf The message it forwards (its text, mentions already plain); null: the author's own text
     */
    public function __construct(
        public ChatConversation $conversation,
        public string $body,
        public ?ChatMessage $threadRoot = null,
        public ?ChatMessage $forwardOf = null,
    ) {
    }
}

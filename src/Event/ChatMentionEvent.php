<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * A stored message mentions conversation members (dispatched after the flush). On an edit it carries only the people
 * the edit added, so a listener notifies each person at most once per message. The bundle never notifies by itself
 * beyond the realtime message event: hosts listen to this to send a toast, a push or an e-mail.
 */
final readonly class ChatMentionEvent
{
    /**
     * Builds the event from the message, the newly mentioned users and the conversation.
     *
     * @param ChatMessage $message The message that mentions them
     * @param list<ChatUserInterface> $users Members mentioned for the first time in this message (never the author)
     * @param ChatConversation $conversation The conversation of the message
     * @param bool $edited True when an edit added the mentions
     */
    public function __construct(
        public ChatMessage $message,
        public array $users,
        public ChatConversation $conversation,
        public bool $edited = false,
    ) {
    }
}

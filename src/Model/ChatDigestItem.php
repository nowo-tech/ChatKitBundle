<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * One conversation in an unread-messages email: who / where, how many, the last message, a link.
 */
final readonly class ChatDigestItem
{
    /**
     * Builds one digest line from the conversation title, its unread count, the last message preview and a link.
     *
     * @param string $title Conversation title (person, channel or support)
     * @param int $unread Number of unread messages
     * @param string $preview Plain text of the newest message
     * @param string $url Link that opens the conversation
     */
    public function __construct(
        public string $title,
        public int $unread,
        /** Plain text of the newest message (empty for a buzz or a deleted message). */
        public string $preview,
        /** Opens the conversation (the profile's `push_url` with the conversation id). */
        public string $url,
    ) {
    }
}

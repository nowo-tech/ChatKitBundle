<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * One conversation in an unread-messages email: who / where, how many, the last message, a link.
 */
final readonly class ChatDigestItem
{
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

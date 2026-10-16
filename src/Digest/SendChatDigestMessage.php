<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Digest;

/**
 * Check one user's unread conversations and email them if still unread (dispatched with a delay).
 */
final readonly class SendChatDigestMessage
{
    /**
     * Builds the message for one user.
     *
     * @param int $userId Doctrine id of the user to check
     */
    public function __construct(
        public int $userId,
    ) {
    }
}

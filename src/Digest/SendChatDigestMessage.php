<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Digest;

use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;

/**
 * Check one user's unread conversations and email them if still unread (dispatched with a delay).
 */
final readonly class SendChatDigestMessage
{
    /**
     * Builds the message for one user.
     *
     * @param int|string $userId Key of the user to check (ChatUserReferences::key(); 1.27: a string reference too)
     */
    public function __construct(
        public int|string $userId,
    ) {
    }
}

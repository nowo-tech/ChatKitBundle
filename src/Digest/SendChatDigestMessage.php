<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Digest;

/**
 * Check one user's unread conversations and email them if still unread (dispatched with a delay).
 */
final readonly class SendChatDigestMessage
{
    public function __construct(
        public int $userId,
    ) {
    }
}

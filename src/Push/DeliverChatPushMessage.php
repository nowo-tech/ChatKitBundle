<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Push;

use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;

/**
 * Web Push for one new message (or buzz) to the given candidate recipients, off the request when Messenger
 * routes it to a transport.
 */
final readonly class DeliverChatPushMessage
{
    /**
     * @param list<int|string> $recipientIds User keys (ChatUserReferences::key(); 1.27: string references too)
     */
    public function __construct(
        public int $messageId,
        public array $recipientIds,
    ) {
    }
}

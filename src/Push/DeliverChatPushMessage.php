<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Push;

/**
 * Web Push for one new message (or buzz) to the given candidate recipients, off the request when Messenger
 * routes it to a transport.
 */
final readonly class DeliverChatPushMessage
{
    /**
     * @param list<int> $recipientIds
     */
    public function __construct(
        public int $messageId,
        public array $recipientIds,
    ) {
    }
}

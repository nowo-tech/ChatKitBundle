<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Push;

use Nowo\ChatKitBundle\Contract\ChatPushSenderInterface;

/**
 * Default: no Web Push (the application did not provide a sender).
 */
final class NullChatPushSender implements ChatPushSenderInterface
{
    public function send(array $recipients, callable $payloadFor): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Push;

use Nowo\ChatKitBundle\Contract\ChatPushSenderInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Default: no Web Push (the application did not provide a sender).
 */
final class NullChatPushSender implements ChatPushSenderInterface
{
    /**
     * Does nothing: no Web Push is sent.
     *
     * @param list<ChatUserInterface> $recipients Ignored
     * @param callable(ChatUserInterface): array<string, string> $payloadFor Ignored
     */
    public function send(array $recipients, callable $payloadFor): void
    {
    }
}

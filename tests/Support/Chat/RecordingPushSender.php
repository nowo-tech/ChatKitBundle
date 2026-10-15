<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatPushSenderInterface;

final class RecordingPushSender implements ChatPushSenderInterface
{
    /** @var array<string, array<string, string>> public id => payload */
    public array $sent = [];

    public function send(array $recipients, callable $payloadFor): void
    {
        foreach ($recipients as $user) {
            $this->sent[$user->getChatPublicId()] = $payloadFor($user);
        }
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatDigestSenderInterface;
use Nowo\ChatKitBundle\Model\ChatDigestItem;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

final class RecordingDigestSender implements ChatDigestSenderInterface
{
    /** @var list<array{user: ChatUserInterface, items: list<ChatDigestItem>}> */
    public array $sent = [];

    public function send(ChatUserInterface $user, array $items): void
    {
        $this->sent[] = ['user' => $user, 'items' => $items];
    }
}

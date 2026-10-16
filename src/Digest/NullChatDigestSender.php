<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Digest;

use Nowo\ChatKitBundle\Contract\ChatDigestSenderInterface;
use Nowo\ChatKitBundle\Model\ChatDigestItem;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Default: no unread-messages email.
 */
final class NullChatDigestSender implements ChatDigestSenderInterface
{
    /**
     * Does nothing: no email is sent.
     *
     * @param ChatUserInterface $user Ignored
     * @param list<ChatDigestItem> $items Ignored
     */
    public function send(ChatUserInterface $user, array $items): void
    {
    }
}

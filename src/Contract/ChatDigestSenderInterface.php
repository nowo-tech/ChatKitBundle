<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatDigestItem;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Sends the unread-messages email with the application's mailer and templates (optional; default: no email).
 * The bundle decides when: messages still unread after `digest.delay_minutes`, the app not open, at most one
 * email per `digest.interval_minutes`, the user's opt-out respected.
 */
interface ChatDigestSenderInterface
{
    /**
     * Sends one unread-messages email to the user.
     *
     * @param ChatUserInterface $user The recipient
     * @param list<ChatDigestItem> $items newest activity first
     */
    public function send(ChatUserInterface $user, array $items): void;
}

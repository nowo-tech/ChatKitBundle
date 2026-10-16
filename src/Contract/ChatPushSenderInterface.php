<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Delivers Web Push notifications (the application's push stack). The bundle has already filtered recipients
 * (participants who enabled push, unread, not muted, Beacon not open). Default: no push.
 */
interface ChatPushSenderInterface
{
    /**
     * Sends one Web Push notification to each recipient.
     *
     * @param list<ChatUserInterface> $recipients Already filtered recipients
     * @param callable(ChatUserInterface): array<string, string> $payloadFor title, body, tag, url, icon …
     */
    public function send(array $recipients, callable $payloadFor): void;
}

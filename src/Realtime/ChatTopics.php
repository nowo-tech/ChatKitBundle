<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Realtime;

/**
 * One private topic per user. Subscriber tokens only ever grant the caller's own topic; the server decides who
 * receives each event (ChatRecipients), so access is re-checked on every publish.
 */
final class ChatTopics
{
    public static function forUser(string $publicId): string
    {
        return \sprintf('/nowo-chat/users/%s', $publicId);
    }
}

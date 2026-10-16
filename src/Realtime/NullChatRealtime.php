<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Realtime;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * No realtime transport: the dock falls back to polling (catch-up on focus and on an interval).
 */
final class NullChatRealtime implements ChatRealtimeInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function publish(array $topics, array $payload): void
    {
    }

    public function browserHubUrl(Request $request): ?string
    {
        return null;
    }

    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
    {
        return null;
    }
}

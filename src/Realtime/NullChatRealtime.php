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
    /**
     * Never enabled: the dock polls instead.
     *
     * @return bool Always false
     */
    public function isEnabled(): bool
    {
        return false;
    }

    /**
     * Does nothing.
     *
     * @param list<string> $topics Ignored
     * @param array<string, mixed> $payload Ignored
     */
    public function publish(array $topics, array $payload): void
    {
    }

    /**
     * There is no hub.
     *
     * @param Request $request Ignored
     *
     * @return string|null Always null
     */
    public function browserHubUrl(Request $request): ?string
    {
        return null;
    }

    /**
     * There is no hub, hence no token.
     *
     * @param list<string> $topics Ignored
     * @param DateTimeImmutable $expiresAt Ignored
     *
     * @return string|null Always null
     */
    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
    {
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Symfony\Component\HttpFoundation\Request;

/**
 * Realtime transport (Mercure by default). Topics are private and per user; the bundle decides recipients.
 */
interface ChatRealtimeInterface
{
    public function isEnabled(): bool;

    /**
     * @param list<string>         $topics
     * @param array<string, mixed> $payload
     */
    public function publish(array $topics, array $payload): void;

    /** Hub URL as the browser must use it (same-origin when possible), null when disabled. */
    public function browserHubUrl(Request $request): ?string;

    /**
     * Subscriber JWT limited to the given topics and expiring at `$expiresAt`; null when disabled.
     *
     * @param list<string> $topics
     */
    public function subscriberToken(array $topics, \DateTimeImmutable $expiresAt): ?string;
}

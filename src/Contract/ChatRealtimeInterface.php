<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * Realtime transport (Mercure by default). Topics are private and per user; the bundle decides recipients.
 */
interface ChatRealtimeInterface
{
    /**
     * Whether a realtime transport is available (false: the dock polls).
     *
     * @return bool True when events are published
     */
    public function isEnabled(): bool;

    /**
     * Publishes one private event to the given topics.
     *
     * @param list<string> $topics Per-user private topics
     * @param array<string, mixed> $payload Event data (JSON-encoded by the transport)
     */
    public function publish(array $topics, array $payload): void;

    /**
     * Hub URL as the browser must use it (same-origin when possible), null when disabled.
     *
     * @param Request $request The current request (to build a same-origin URL)
     *
     * @return string|null The hub URL, or null
     */
    public function browserHubUrl(Request $request): ?string;

    /**
     * Subscriber JWT limited to the given topics and expiring at `$expiresAt`; null when disabled.
     *
     * @param list<string> $topics Topics the token may subscribe to
     * @param DateTimeImmutable $expiresAt Token expiry
     *
     * @return string|null The JWT, or null
     */
    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string;
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Model\ChatRequestContext;

/**
 * 1.27: framework-free twin of ChatRealtimeInterface (REQ-ARCH-001 rules 1 and 3): the browser hub URL is built from a
 * ChatRequestContext instead of the HttpFoundation `Request`. Alias ChatRealtimeInterface (or this interface) to a
 * service that implements it; the bundle asks it through an internal gate. A service that also implements the
 * deprecated ChatRealtimeInterface is asked through that one, exactly as in 1.26.
 */
interface ChatRealtimeTransportInterface
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
     * @param ChatRequestContext $request The current request (its origin builds a same-origin URL)
     *
     * @return string|null The hub URL, or null
     */
    public function browserHubUrl(ChatRequestContext $request): ?string;

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

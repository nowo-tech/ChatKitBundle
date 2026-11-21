<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Model\ChatRequestContext;

/**
 * 1.27: framework-free twin of the 1.x ChatRealtimeInterface (REQ-ARCH-001 rules 1 and 3): the browser hub URL is built
 * from a ChatRequestContext instead of the HttpFoundation `Request`. 2.0: the only host interface of the realtime port
 * (ChatRealtimeInterface was removed). Alias this interface to a service that implements it; the bundle asks it through
 * an internal gate. Without one the bundle uses Mercure (when a hub is configured) or the null transport.
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

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Port;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeTransportInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * 1.27: asks a host ChatRealtimeTransportInterface where the bundle asks the deprecated ChatRealtimeInterface.
 * Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports}
 */
final readonly class ChatRealtimeTransportGate implements ChatRealtimeInterface
{
    /**
     * @param ChatRealtimeTransportInterface $transport The host's twin
     */
    public function __construct(public ChatRealtimeTransportInterface $transport)
    {
    }

    /**
     * Whether the transport is available.
     *
     * @return bool The transport's answer
     */
    public function isEnabled(): bool
    {
        return $this->transport->isEnabled();
    }

    /**
     * Publishes one private event.
     *
     * @param list<string> $topics Per-user private topics
     * @param array<string, mixed> $payload Event data
     */
    public function publish(array $topics, array $payload): void
    {
        $this->transport->publish($topics, $payload);
    }

    /**
     * The browser hub URL, asked with the request copied into a ChatRequestContext.
     *
     * @param Request $request The current request
     *
     * @return string|null The transport's answer
     */
    public function browserHubUrl(Request $request): ?string
    {
        return $this->transport->browserHubUrl(ChatRequestContexts::fromRequest($request));
    }

    /**
     * A subscriber token.
     *
     * @param list<string> $topics Topics the token may subscribe to
     * @param DateTimeImmutable $expiresAt Token expiry
     *
     * @return string|null The transport's answer
     */
    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
    {
        return $this->transport->subscriberToken($topics, $expiresAt);
    }
}

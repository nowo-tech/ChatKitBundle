<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Realtime;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

use const JSON_THROW_ON_ERROR;

/**
 * Default transport when symfony/mercure-bundle is installed: private updates on the default hub.
 */
final readonly class MercureChatRealtime implements ChatRealtimeInterface
{
    /**
     * Builds the transport on top of the default Mercure hub.
     *
     * @param HubInterface $hub The Mercure hub
     */
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    /**
     * Always enabled when Mercure is installed.
     *
     * @return bool Always true
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Publishes the JSON-encoded payload as a private Mercure update.
     *
     * @param list<string> $topics Per-user private topics
     * @param array<string, mixed> $payload Event data
     */
    public function publish(array $topics, array $payload): void
    {
        $this->hub->publish(new Update($topics, json_encode($payload, JSON_THROW_ON_ERROR), true));
    }

    /**
     * Returns the hub's public URL.
     *
     * @param Request $request Unused; the hub configuration defines the URL
     *
     * @return string The public hub URL
     */
    public function browserHubUrl(Request $request): string
    {
        return $this->hub->getPublicUrl();
    }

    /**
     * Creates a subscriber JWT with the hub's token factory.
     *
     * @param list<string> $topics Topics the token may subscribe to
     * @param DateTimeImmutable $expiresAt Token expiry (`exp` claim)
     *
     * @return string|null The JWT, or null when the hub has no token factory
     */
    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
    {
        return $this->hub->getFactory()?->create($topics, [], ['exp' => $expiresAt]);
    }
}

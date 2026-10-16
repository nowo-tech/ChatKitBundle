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
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function publish(array $topics, array $payload): void
    {
        $this->hub->publish(new Update($topics, json_encode($payload, JSON_THROW_ON_ERROR), true));
    }

    public function browserHubUrl(Request $request): string
    {
        return $this->hub->getPublicUrl();
    }

    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
    {
        return $this->hub->getFactory()?->create($topics, [], ['exp' => $expiresAt]);
    }
}

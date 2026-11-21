<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\AliasOfRealtime;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatRealtimeTransportInterface;
use Nowo\ChatKitBundle\Model\ChatRequestContext;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * 2.0 W1-1 fixture (autodiscovered): an application service moved to ChatRealtimeTransportInterface but still aliased under the removed ChatRealtimeInterface.
 */
#[AsAlias('Nowo\ChatKitBundle\Contract\ChatRealtimeInterface')]
final class HostRealtime implements ChatRealtimeTransportInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function publish(array $topics, array $payload): void
    {
    }

    public function browserHubUrl(ChatRequestContext $request): ?string
    {
        return null;
    }

    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
    {
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\DecoratorOfRealtime;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * 2.0 W1-1 fixture (autodiscovered): a 1.x application decorator of the removed ChatRealtimeInterface (the interface no longer exists, so the class cannot be
 * loaded; Symfony would drop it silently).
 */
#[AsDecorator('Nowo\ChatKitBundle\Contract\ChatRealtimeInterface')]
final class AuditedRealtime implements ChatRealtimeInterface
{
    public function __construct(#[AutowireDecorated] private readonly ChatRealtimeInterface $inner)
    {
    }

    public function isEnabled(): bool
    {
        return $this->inner->isEnabled();
    }

    public function publish(array $topics, array $payload): void
    {
        $this->inner->publish($topics, $payload);
    }

    public function browserHubUrl(Request $request): ?string
    {
        return $this->inner->browserHubUrl($request);
    }

    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
    {
        return $this->inner->subscriberToken($topics, $expiresAt);
    }
}

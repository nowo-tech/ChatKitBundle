<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Records published events (and can simulate a hub outage).
 */
final class RecordingRealtime implements ChatRealtimeInterface
{
    /** @var list<array{topics: list<string>, payload: array<string, mixed>}> */
    public array $published = [];

    public bool $failing = false;

    public function isEnabled(): bool
    {
        return true;
    }

    public function publish(array $topics, array $payload): void
    {
        if ($this->failing) {
            throw new RuntimeException('hub down');
        }
        $this->published[] = ['topics' => $topics, 'payload' => $payload];
    }

    public function browserHubUrl(Request $request): string
    {
        return 'https://hub.example.test/.well-known/mercure';
    }

    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): string
    {
        return 'token:' . implode(',', $topics) . '@' . $expiresAt->getTimestamp();
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(static fn (array $event): string => (string) $event['payload']['type'], $this->published);
    }
}

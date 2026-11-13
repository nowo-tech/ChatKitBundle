<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\TwinPorts;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatRealtimeTransportInterface;
use Nowo\ChatKitBundle\Model\ChatRequestContext;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Symfony\Component\HttpFoundation\Request;

/**
 * 1.27: the recording transport behind the framework-free twin ONLY; same answers as RecordingRealtime. The last
 * request context it was given is kept for the tests.
 */
final class TwinRealtime implements ChatRealtimeTransportInterface
{
    public ?ChatRequestContext $lastRequest = null;

    public function __construct(private readonly RecordingRealtime $realtime)
    {
    }

    public function isEnabled(): bool
    {
        return $this->realtime->isEnabled();
    }

    public function publish(array $topics, array $payload): void
    {
        $this->realtime->publish($topics, $payload);
    }

    public function browserHubUrl(ChatRequestContext $request): ?string
    {
        $this->lastRequest = $request;

        return $this->realtime->browserHubUrl(Request::create($request->origin() . $request->path));
    }

    public function subscriberToken(array $topics, DateTimeImmutable $expiresAt): ?string
    {
        return $this->realtime->subscriberToken($topics, $expiresAt);
    }
}

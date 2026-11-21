<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\DecoratorOfChannels;

use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * 2.0 W1-1 fixture (autodiscovered): a 1.x application decorator of the removed ChatChannelProviderInterface (the interface no longer exists, so the class cannot be
 * loaded; Symfony would drop it silently).
 */
#[AsDecorator('Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface')]
final class AuditedChannels implements ChatChannelProviderInterface
{
    public function __construct(#[AutowireDecorated] private readonly ChatChannelProviderInterface $inner)
    {
    }

    public function channelsFor(ChatUserInterface $user): array
    {
        return $this->inner->channelsFor($user);
    }

    public function find(string $key): ?ChatChannel
    {
        return $this->inner->find($key);
    }

    public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel
    {
        return $this->inner->contextChannel($user, $request);
    }

    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return $this->inner->canUse($user, $channel);
    }

    public function members(ChatChannel $channel): array
    {
        return $this->inner->members($channel);
    }
}

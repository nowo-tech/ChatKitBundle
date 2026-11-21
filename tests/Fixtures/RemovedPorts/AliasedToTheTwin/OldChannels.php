<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\AliasedToTheTwin;

use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * 2.0 W1-1 fixture (registered explicitly, aliased to the twin's id): a class that still implements only the removed
 * channel provider interface.
 */
final class OldChannels implements ChatChannelProviderInterface
{
    public function channelsFor(ChatUserInterface $user): array
    {
        return [];
    }

    public function find(string $key): ?ChatChannel
    {
        return null;
    }

    public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel
    {
        return null;
    }

    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return false;
    }

    public function members(ChatChannel $channel): array
    {
        return [];
    }
}

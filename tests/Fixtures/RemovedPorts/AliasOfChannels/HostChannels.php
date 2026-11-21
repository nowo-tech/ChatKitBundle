<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Fixtures\RemovedPorts\AliasOfChannels;

use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatRequestContext;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * 2.0 W1-1 fixture (autodiscovered): an application service moved to ChatChannelSourceInterface but still aliased under the removed ChatChannelProviderInterface.
 */
#[AsAlias('Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface')]
final class HostChannels implements ChatChannelSourceInterface
{
    public function channelsFor(ChatUserInterface $user): array
    {
        return [];
    }

    public function find(string $key): ?ChatChannel
    {
        return null;
    }

    public function contextChannel(ChatUserInterface $user, ChatRequestContext $page): ?ChatChannel
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

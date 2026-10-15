<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Channel;

use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Default: the application defines no channels (direct messages and support only).
 */
final class NullChannelProvider implements ChatChannelProviderInterface
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

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
    /**
     * There are no channels.
     *
     * @param ChatUserInterface $user Ignored
     *
     * @return list<ChatChannel> Always empty
     */
    public function channelsFor(ChatUserInterface $user): array
    {
        return [];
    }

    /**
     * No key matches a channel.
     *
     * @param string $key Ignored
     *
     * @return ChatChannel|null Always null
     */
    public function find(string $key): ?ChatChannel
    {
        return null;
    }

    /**
     * No page has a channel.
     *
     * @param ChatUserInterface $user Ignored
     * @param Request $request Ignored
     *
     * @return ChatChannel|null Always null
     */
    public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel
    {
        return null;
    }

    /**
     * Nobody can use a channel.
     *
     * @param ChatUserInterface $user Ignored
     * @param ChatChannel $channel Ignored
     *
     * @return bool Always false
     */
    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return false;
    }

    /**
     * Channels have no members.
     *
     * @param ChatChannel $channel Ignored
     *
     * @return list<ChatUserInterface> Always empty
     */
    public function members(ChatChannel $channel): array
    {
        return [];
    }
}

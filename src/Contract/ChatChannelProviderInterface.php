<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Group conversations defined by the application (e.g. one channel per project). Optional: the default
 * provider has no channels.
 */
interface ChatChannelProviderInterface
{
    /**
     * Channels the user can use (listed in the dock).
     *
     * @param ChatUserInterface $user The viewer
     *
     * @return list<ChatChannel> The usable channels
     */
    public function channelsFor(ChatUserInterface $user): array;

    /**
     * Looks a channel up by its stable key.
     *
     * @param string $key Channel key such as `project:<uuid>`
     *
     * @return ChatChannel|null The channel, or null when the key is unknown
     */
    public function find(string $key): ?ChatChannel;

    /**
     * The channel of the page being viewed (e.g. the project page), offered as a shortcut in the people
     * picker; null when the page has none.
     *
     * @param ChatUserInterface $user The viewer
     * @param Request $request The current page request
     *
     * @return ChatChannel|null The page's channel, or null
     */
    public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel;

    /**
     * Whether the user may read and write in the channel.
     *
     * @param ChatUserInterface $user The user to check
     * @param ChatChannel $channel The channel
     *
     * @return bool True when the user can use the channel
     */
    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool;

    /**
     * Everyone who can use the channel (realtime recipients).
     *
     * @param ChatChannel $channel The channel
     *
     * @return list<ChatUserInterface> The channel members
     */
    public function members(ChatChannel $channel): array;
}

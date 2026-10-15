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
     * @return list<ChatChannel>
     */
    public function channelsFor(ChatUserInterface $user): array;

    public function find(string $key): ?ChatChannel;

    /**
     * The channel of the page being viewed (e.g. the project page), offered as a shortcut in the people
     * picker; null when the page has none.
     */
    public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel;

    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool;

    /**
     * Everyone who can use the channel (realtime recipients).
     *
     * @return list<ChatUserInterface>
     */
    public function members(ChatChannel $channel): array;
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

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

    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool;

    /**
     * Everyone who can use the channel (realtime recipients).
     *
     * @return list<ChatUserInterface>
     */
    public function members(ChatChannel $channel): array;
}

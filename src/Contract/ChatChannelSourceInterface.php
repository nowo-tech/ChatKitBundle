<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatRequestContext;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.27: framework-free twin of the 1.x ChatChannelProviderInterface (REQ-ARCH-001 rules 1 and 3): the same questions,
 * with the page request as a ChatRequestContext instead of the HttpFoundation `Request`. 2.0: the only host interface
 * of the channel port (ChatChannelProviderInterface was removed). Alias this interface to a service that implements it;
 * the bundle asks it through an internal gate. The optional companions (ChatChannelWritePolicyInterface,
 * ChatChannelMentionPolicyInterface) are read on the same service.
 */
interface ChatChannelSourceInterface
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
     * Looks a channel up by its stable key. Return the channel of exactly this key (REQ-SEC-006 item 4: the bundle
     * refuses another key).
     *
     * @param string $key Channel key such as `project:<uuid>`
     *
     * @return ChatChannel|null The channel, or null when the key is unknown
     */
    public function find(string $key): ?ChatChannel;

    /**
     * The channel of the page being viewed (e.g. the project page), offered as a shortcut in the people picker; null
     * when the page has none.
     *
     * @param ChatUserInterface $user The viewer
     * @param ChatRequestContext $page The current page request
     *
     * @return ChatChannel|null The page's channel, or null
     */
    public function contextChannel(ChatUserInterface $user, ChatRequestContext $page): ?ChatChannel;

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

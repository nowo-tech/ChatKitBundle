<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Optional (1.19), implemented by the application's access policy: who manages the pinned links of a channel when
 * `links.channel_managers` is `admins` (e.g. the people who manage the channel's project). Without it, `admins` falls
 * back to ChatPinPolicyInterface::canPinInChannel(), and to nobody without that either (deny by default). With
 * `channel_managers: members` (default) every member of the channel manages them. In direct conversations both people
 * manage them; in support tickets the agents.
 */
interface ChatLinkPolicyInterface
{
    /**
     * Whether the user may add, change and remove the pinned links of the channel.
     *
     * @param ChatUserInterface $user The user
     * @param ChatChannel $channel The channel
     *
     * @return bool True when allowed
     */
    public function canManageLinksInChannel(ChatUserInterface $user, ChatChannel $channel): bool;
}

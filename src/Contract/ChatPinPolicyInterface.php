<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Optional (1.10), implemented by the application's access policy: who may pin and unpin messages in a channel (e.g.
 * the people who manage the channel's project). Without it nobody pins in channels (deny by default). In direct
 * conversations both people pin; in support tickets the agents.
 */
interface ChatPinPolicyInterface
{
    /**
     * Whether the user may pin and unpin messages of the channel.
     *
     * @param ChatUserInterface $user The user
     * @param ChatChannel $channel The channel
     *
     * @return bool True when allowed
     */
    public function canPinInChannel(ChatUserInterface $user, ChatChannel $channel): bool;
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Optional (1.24), implemented by the application's channel provider (ChatChannelProviderInterface): who may mention
 * a whole channel with `@channel` (every member) or `@here` (every member online now). Without it nobody may: the
 * words stay plain text and nobody is notified. Even when allowed, group mentions are rate limited per writer
 * (`limits.mention_all_per_hour`); over the limit the message is sent without the group mention.
 */
interface ChatChannelMentionPolicyInterface
{
    /**
     * Whether the writer may mention every member (or every online member) of the channel.
     *
     * @param ChatUserInterface $user The writer (a member who may write in the channel)
     * @param ChatChannel $channel The channel
     * @param int $memberCount How many people can read the channel now (the writer included)
     *
     * @return bool True when the group mention notifies the members
     */
    public function canMentionAll(ChatUserInterface $user, ChatChannel $channel, int $memberCount): bool;
}

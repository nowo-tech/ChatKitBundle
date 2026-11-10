<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Optional (1.24), implemented by the application's channel provider (ChatChannelProviderInterface): who may write in
 * a channel they can read — e.g. nobody in an archived room, or only its admins in an announcements channel. Asked on
 * the server for every send, edit, reaction, typing ping, pin and link change (ChatVoter / ChatAccess), never only in
 * the dock. Without it every member writes unless the channel is `ChatChannel::$readOnly`. Deleting one's own message
 * within the edit window stays allowed in a channel the member may only read (people can remove what they wrote).
 */
interface ChatChannelWritePolicyInterface
{
    /**
     * Whether the user, who can use the channel (ChatChannelProviderInterface::canUse()), may also write in it.
     *
     * @param ChatUserInterface $user The member
     * @param ChatChannel $channel The channel
     *
     * @return bool True when the member may write
     */
    public function canWrite(ChatUserInterface $user, ChatChannel $channel): bool;
}

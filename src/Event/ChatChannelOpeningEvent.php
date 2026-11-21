<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * 1.21: a channel's conversation is about to be created (first opening, the actor may already use the channel): nothing
 * is written yet. A listener refuses with deny() (plan limit, quota …): no conversation row, no participant row, and the
 * API answers 403 `channel_denied` with the reason. A channel whose conversation exists opens without this event.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatChannelOpeningEvent extends Event
{
    /** The channel's stable key, such as `project:<uuid>`. */
    public readonly string $channelKey;

    /** What the key says before its first `:` (`project` for `project:<uuid>`); null when it has none. */
    public readonly ?string $scope;
    private bool $denied   = false;
    private string $reason = '';

    /**
     * @param ChatChannel $channel The channel being opened
     * @param ChatUserInterface $user Who opens it
     */
    public function __construct(
        public readonly ChatChannel $channel,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
        public readonly ChatUserInterface $user,
    ) {
        $this->channelKey = $channel->key;
        $position         = strpos($channel->key, ':');
        $this->scope      = $position === false || $position === 0 ? null : substr($channel->key, 0, $position);
    }

    /**
     * Refuses the opening; later listeners do not run.
     *
     * @param string $reason Text for the person, already translated and plain (the dock prints it as text); empty = the
     *                       dock's generic error
     */
    public function deny(string $reason = ''): void
    {
        // @igor-ignore One object per dispatch, never a service: nothing outlives the request.
        $this->denied = true;
        // @igor-ignore One object per dispatch, never a service: nothing outlives the request.
        $this->reason = $reason;
        $this->stopPropagation();
    }

    /**
     * Whether a listener refused the opening.
     *
     * @return bool True when refused
     */
    public function isDenied(): bool
    {
        return $this->denied;
    }

    /**
     * The text the refusing listener gave.
     *
     * @return string The reason; empty when none was given
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * 2.0: The requesting user. The key the chat stores for the user: the host user id as a string (`getReference()` / compare with your own id).
     */
    public function userReference(): string
    {
        return EventRefs::user($this->user);
    }

    /**
     * 2.0: the user public id.
     */
    public function userPublicId(): string
    {
        return $this->user->getChatPublicId();
    }
}

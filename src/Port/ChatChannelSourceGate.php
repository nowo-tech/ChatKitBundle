<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Port;

use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * 1.27: asks a host ChatChannelSourceInterface where the bundle asks the deprecated ChatChannelProviderInterface. The
 * optional companions (write and mention policies) are read on the source itself ({@see Ports::companion()}).
 * Exceptions propagate. Stateless.
 *
 * @internal built by {@see Ports}
 */
final readonly class ChatChannelSourceGate implements ChatChannelProviderInterface
{
    /**
     * @param ChatChannelSourceInterface $source The host's twin
     */
    public function __construct(public ChatChannelSourceInterface $source)
    {
    }

    /**
     * Channels the user can use.
     *
     * @param ChatUserInterface $user The viewer
     *
     * @return list<ChatChannel> The source's answer
     */
    public function channelsFor(ChatUserInterface $user): array
    {
        return $this->source->channelsFor($user);
    }

    /**
     * A channel by key.
     *
     * @param string $key Channel key
     *
     * @return ChatChannel|null The source's answer
     */
    public function find(string $key): ?ChatChannel
    {
        return $this->source->find($key);
    }

    /**
     * The page's channel, asked with the request copied into a ChatRequestContext.
     *
     * @param ChatUserInterface $user The viewer
     * @param Request $request The page request
     *
     * @return ChatChannel|null The source's answer
     */
    public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel
    {
        return $this->source->contextChannel($user, ChatRequestContexts::fromRequest($request));
    }

    /**
     * Whether the user can use the channel.
     *
     * @param ChatUserInterface $user The user
     * @param ChatChannel $channel The channel
     *
     * @return bool The source's answer
     */
    public function canUse(ChatUserInterface $user, ChatChannel $channel): bool
    {
        return $this->source->canUse($user, $channel);
    }

    /**
     * The channel's members.
     *
     * @param ChatChannel $channel The channel
     *
     * @return list<ChatUserInterface> The source's answer
     */
    public function members(ChatChannel $channel): array
    {
        return $this->source->members($channel);
    }
}

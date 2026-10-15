<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Security;

use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The single place that decides reachability, on top of the application's policy and channel provider.
 * Answers are memoized for the current request only (FrankenPHP worker mode: reset between requests), so
 * losing a shared context turns a direct thread read-only and hides a channel on the next request.
 */
final class ChatAccess implements ResetInterface
{
    /** @var array<int, bool> */
    private array $agents = [];

    /** @var array<string, ChatChannel|false> */
    private array $channels = [];

    /** @var array<string, bool> "userId:channelKey" => access */
    private array $channelAccess = [];

    public function __construct(
        private readonly ChatAccessPolicyInterface $policy,
        private readonly ChatChannelProviderInterface $channelProvider,
        private readonly ChatDirectoryInterface $directory,
    ) {
    }

    public function reset(): void
    {
        $this->agents = [];
        $this->channels = [];
        $this->channelAccess = [];
    }

    public function isReachable(ChatUserInterface $user): bool
    {
        return null !== $user->getId() && $this->policy->isReachable($user);
    }

    public function isSupportAgent(ChatUserInterface $user): bool
    {
        return $this->agents[(int) $user->getId()] ??= $this->policy->isSupportAgent($user);
    }

    /** Start a direct conversation: both reachable, two different people, allowed by the policy. */
    public function canOpenDirect(ChatUserInterface $actor, ChatUserInterface $other): bool
    {
        return $this->isReachable($actor) && $this->isReachable($other) && $actor->getId() !== $other->getId()
            && $this->policy->canMessage($actor, $other);
    }

    /** Write in an existing direct conversation: as above, or replying to a support agent who opened it. */
    public function canWriteDirect(ChatUserInterface $actor, ChatUserInterface $other): bool
    {
        return $this->canOpenDirect($actor, $other)
            || ($this->isReachable($actor) && $this->isReachable($other) && $actor->getId() !== $other->getId() && $this->isSupportAgent($other));
    }

    public function channel(string $key): ?ChatChannel
    {
        $channel = $this->channels[$key] ??= $this->channelProvider->find($key) ?? false;

        return false === $channel ? null : $channel;
    }

    public function canUseChannel(ChatUserInterface $user, string $key): bool
    {
        return $this->channelAccess[$user->getId().':'.$key] ??= $this->isReachable($user)
            && ($channel = $this->channel($key)) instanceof ChatChannel
            && $this->channelProvider->canUse($user, $channel);
    }

    /** The other user of a direct conversation, or null when the actor is not part of it / the account is gone. */
    public function directPeer(ChatConversation $conversation, ChatUserInterface $actor): ?ChatUserInterface
    {
        $peerId = $conversation->peerIdOf($actor);

        return null === $peerId ? null : ($this->directory->findByIds([$peerId])[0] ?? null);
    }
}

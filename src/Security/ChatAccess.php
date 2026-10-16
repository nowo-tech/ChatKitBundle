<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Security;

use Closure;
use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;

/**
 * The single place that decides reachability, on top of the application's policy and channel provider.
 * Answers are memoized on the current request object only (never in this service, so FrankenPHP workers without a
 * kernel reset cannot carry them over), so
 * losing a shared context turns a direct thread read-only and hides a channel on the next request.
 */
final class ChatAccess implements ResetInterface
{
    private const string MEMO = '_nowo_chat_access';

    /**
     * Builds the access checks on top of the application's policy, channel provider and directory.
     *
     * @param ChatAccessPolicyInterface $policy Who can talk to whom
     * @param ChatChannelProviderInterface $channelProvider Application channels
     * @param ChatDirectoryInterface $directory Loads direct peers
     */
    public function __construct(
        private readonly ChatAccessPolicyInterface $policy,
        private readonly ChatChannelProviderInterface $channelProvider,
        private readonly ChatDirectoryInterface $directory,
        private readonly ?RequestStack $requests = null,
    ) {
    }

    /**
     * Clears the per-request memoized answers (agents, channels, channel access).
     */
    public function reset(): void
    {
        $this->requests?->getMainRequest()?->attributes->remove(self::MEMO);
    }

    /**
     * Whether the user may read the conversation now: a direct thread stays readable for both people; a support
     * thread for its owner and the current agents; a channel for whoever the provider allows today. Used by the voter,
     * the conversation lists and the email digest (participant rows alone never grant access).
     *
     * @param ChatConversation $conversation The conversation to check
     * @param ChatUserInterface $user The reader
     *
     * @return bool True when the user may read it
     */
    public function canView(ChatConversation $conversation, ChatUserInterface $user): bool
    {
        if ($conversation->isDirect()) {
            return $conversation->involvesDirectly($user);
        }
        if ($conversation->isSupport()) {
            return $conversation->getSupportOwner()?->getId() === $user->getId() || $this->isSupportAgent($user);
        }

        return $this->canUseChannel($user, (string) $conversation->getChannelKey());
    }

    /**
     * Whether the user is persisted and the policy lets them use the chat.
     *
     * @param ChatUserInterface $user The user to check
     *
     * @return bool True when reachable
     */
    public function isReachable(ChatUserInterface $user): bool
    {
        return $user->getId() !== null && $this->policy->isReachable($user);
    }

    /**
     * Whether the user answers the support inbox (memoized for the request).
     *
     * @param ChatUserInterface $user The user to check
     *
     * @return bool True for a support agent
     */
    public function isSupportAgent(ChatUserInterface $user): bool
    {
        return (bool) $this->memo('agent:' . $user->getId(), fn (): bool => $this->policy->isSupportAgent($user));
    }

    /**
     * Start a direct conversation: both reachable, two different people, allowed by the policy.
     *
     * @param ChatUserInterface $actor The user who opens the conversation
     * @param ChatUserInterface $other The other person
     *
     * @return bool True when the conversation may be opened
     */
    public function canOpenDirect(ChatUserInterface $actor, ChatUserInterface $other): bool
    {
        return $this->isReachable($actor) && $this->isReachable($other) && $actor->getId() !== $other->getId()
            && $this->policy->canMessage($actor, $other);
    }

    /**
     * Write in an existing direct conversation: as above, or replying to a support agent who opened it.
     *
     * @param ChatUserInterface $actor The user who writes
     * @param ChatUserInterface $other The other person of the thread
     *
     * @return bool True when the actor may write
     */
    public function canWriteDirect(ChatUserInterface $actor, ChatUserInterface $other): bool
    {
        return $this->canOpenDirect($actor, $other)
            || ($this->isReachable($actor) && $this->isReachable($other) && $actor->getId() !== $other->getId() && $this->isSupportAgent($other));
    }

    /**
     * Looks a channel up by key through the provider (memoized for the request).
     *
     * @param string $key Channel key
     *
     * @return ChatChannel|null The channel, or null when unknown
     */
    public function channel(string $key): ?ChatChannel
    {
        $channel = $this->memo('channel:' . $key, fn (): ChatChannel|false => $this->channelProvider->find($key) ?? false);

        return $channel instanceof ChatChannel ? $channel : null;
    }

    /**
     * Whether the user is reachable, the channel exists and the provider lets the user use it (memoized).
     *
     * @param ChatUserInterface $user The user to check
     * @param string $key Channel key
     *
     * @return bool True when the user can use the channel
     */
    public function canUseChannel(ChatUserInterface $user, string $key): bool
    {
        return (bool) $this->memo('access:' . $user->getId() . ':' . $key, fn (): bool => $this->isReachable($user)
            && ($channel = $this->channel($key)) instanceof ChatChannel
            && $this->channelProvider->canUse($user, $channel));
    }

    /**
     * The other user of a direct conversation, or null when the actor is not part of it / the account is gone.
     *
     * @param ChatConversation $conversation The direct conversation
     * @param ChatUserInterface $actor One of its two people
     *
     * @return ChatUserInterface|null The peer, or null
     */
    public function directPeer(ChatConversation $conversation, ChatUserInterface $actor): ?ChatUserInterface
    {
        $peerId = $conversation->peerIdOf($actor);

        return $peerId === null ? null : ($this->directory->findByIds([$peerId])[0] ?? null);
    }

    /**
     * Memoizes an answer on the current main request only: nothing survives the request, even when the kernel is never
     * reset (FrankenPHP worker mode); without a request (console, Messenger) nothing is memoized.
     *
     * @param string $key Memo key
     * @param Closure(): mixed $compute Computes the answer on a miss
     *
     * @return mixed The memoized or computed answer
     */
    private function memo(string $key, Closure $compute): mixed
    {
        $request = $this->requests?->getMainRequest();
        if (!$request instanceof Request) {
            return $compute();
        }
        /** @var array<string, mixed> $memo */
        $memo = $request->attributes->get(self::MEMO, []);
        if (!array_key_exists($key, $memo)) {
            $memo[$key] = $compute();
            $request->attributes->set(self::MEMO, $memo);
        }

        return $memo[$key];
    }
}

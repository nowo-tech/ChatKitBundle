<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Security;

use Closure;
use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelWritePolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatLinkPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatPinPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatUserReferenceDirectoryInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\UnresolvedChatUser;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatChannelKey;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Port\Ports;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;
use function in_array;
use function strlen;

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
        private readonly ?ChatUserReferences $refs = null,
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
            return ChatUserReferences::same($conversation->getSupportOwner(), $user) || $this->isSupportAgent($user);
        }

        return $this->canUseChannel($user, (string) $conversation->getChannelKey());
    }

    /**
     * 1.10: whether the user may pin and unpin messages of the conversation — both people of a direct conversation,
     * support agents in tickets, and in channels only whom the application's policy allows (ChatPinPolicyInterface;
     * nobody without it). Always within what the user may read.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $user The user
     *
     * @return bool True when allowed
     */
    public function canPin(ChatConversation $conversation, ChatUserInterface $user): bool
    {
        if (!$this->canView($conversation, $user)) {
            return false;
        }
        if ($conversation->isDirect()) {
            return true;
        }
        if ($conversation->isSupport()) {
            return $this->isSupportAgent($user);
        }
        // The stored key, never the one the provider echoes back: channel() only returns a channel whose key is it.
        $key     = (string) $conversation->getChannelKey();
        $channel = $this->channel($key);

        return $this->policy instanceof ChatPinPolicyInterface && $channel instanceof ChatChannel && $channel->key === $key
            && $this->canWriteChannel($user, $key) && $this->policy->canPinInChannel($user, $channel);
    }

    /**
     * 1.19: whether the user may add, change and remove the pinned links of the conversation — both people of a direct
     * conversation, support agents in tickets, and in channels every member (`$channelMembers`, `links.channel_managers:
     * members`) or only whom the application allows (`admins`: ChatLinkPolicyInterface, else ChatPinPolicyInterface,
     * else nobody). Always within what the user may read.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $user The user
     * @param bool $channelMembers True when every channel member manages the links
     *
     * @return bool True when allowed
     */
    public function canManageLinks(ChatConversation $conversation, ChatUserInterface $user, bool $channelMembers = true): bool
    {
        if (!$this->canView($conversation, $user)) {
            return false;
        }
        if ($conversation->isDirect()) {
            return true;
        }
        if ($conversation->isSupport()) {
            return $this->isSupportAgent($user);
        }
        // 1.24: nobody changes the links of a read-only channel.
        $key = (string) $conversation->getChannelKey();
        if (!$this->canWriteChannel($user, $key)) {
            return false;
        }
        if ($channelMembers) {
            return true;
        }
        $channel = $this->channel($key);
        if (!$channel instanceof ChatChannel || $channel->key !== $key) {
            // The provider no longer knows it (it changed between the calls) or answered with a look-alike: deny.
            return false;
        }
        if ($this->policy instanceof ChatLinkPolicyInterface) {
            return $this->policy->canManageLinksInChannel($user, $channel);
        }

        return $this->policy instanceof ChatPinPolicyInterface && $this->policy->canPinInChannel($user, $channel);
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
        // 1.24: a placeholder for a user the directory no longer knows (`doctrine.user_reference: id`) never is.
        return ChatUserReferences::key($user) !== null && !$user instanceof UnresolvedChatUser
            && ($this->refs === null || $this->refs->fitsKind($user)) && $this->policy->isReachable($user);
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
        return (bool) $this->memo('agent:' . $this->memoKey($user), fn (): bool => $this->policy->isSupportAgent($user));
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
        return $this->isReachable($actor) && $this->isReachable($other) && !ChatUserReferences::same($actor, $other)
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
            || ($this->isReachable($actor) && $this->isReachable($other) && !ChatUserReferences::same($actor, $other) && $this->isSupportAgent($other));
    }

    /**
     * Looks a channel up by key through the provider (memoized for the request). A key that breaks the ChatChannelKey
     * format rule (whitespace at either end, control or format characters, over 120 bytes) is unknown without asking
     * the provider, and so is a channel the provider returns with such a key or with any key other than the one asked
     * for, byte for byte (a provider that folds case, accents or padding must not hand back a look-alike channel; house
     * rule LQ: fail closed).
     *
     * @param string $key Channel key
     *
     * @return ChatChannel|null The channel, or null when unknown
     */
    public function channel(string $key): ?ChatChannel
    {
        if (!ChatChannelKey::isValid($key)) {
            return null;
        }
        $channel = $this->memo('channel:' . $key, fn (): ChatChannel|false => $this->channelProvider->find($key) ?? false);

        return $channel instanceof ChatChannel && $channel->key === $key && ChatChannelKey::isValid($channel->key) ? $channel : null;
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
        return (bool) $this->memo('access:' . $this->memoKey($user) . ':' . $key, fn (): bool => $this->isReachable($user)
            && ($channel = $this->channel($key)) instanceof ChatChannel
            && $this->channelProvider->canUse($user, $channel));
    }

    /**
     * 1.24: whether the user may write in the channel — they can use it, it is not `ChatChannel::$readOnly`, and the
     * provider's ChatChannelWritePolicyInterface (when it implements it) allows it (memoized for the request). Sending,
     * editing, reacting, typing, pinning and changing links in a channel all need it.
     *
     * @param ChatUserInterface $user The user to check
     * @param string $key Channel key
     *
     * @return bool True when the user may write
     */
    public function canWriteChannel(ChatUserInterface $user, string $key): bool
    {
        return (bool) $this->memo('write:' . $this->memoKey($user) . ':' . $key, fn (): bool => $this->canUseChannel($user, $key)
            && ($channel = $this->channel($key)) instanceof ChatChannel
            && !$channel->readOnly
            && (!($policy = Ports::companion($this->channelProvider)) instanceof ChatChannelWritePolicyInterface || $policy->canWrite($user, $channel)));
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
        $peer = $conversation->peerKeyOf($actor);

        return $peer === null ? null : ($this->usersByIds([$peer])[$peer] ?? null);
    }

    /**
     * Users by key through the directory, memoized for the current request: keys already loaded (or known to be gone)
     * are not queried again, so list endpoints load every peer in one query and the per-row voter checks
     * (`directPeer()`) hit the memo instead of the directory (no N+1). 1.27: string references
     * (ChatUserReferenceInterface) go through ChatUserReferenceDirectoryInterface::findByReferences() when the directory
     * implements it; integer ids through ChatDirectoryInterface::findByIds(). Every user the directory returns is kept
     * only when its key is exactly one of the asked keys (REQ-SEC-006 item 4).
     *
     * @param list<int|string> $ids User keys (ChatUserReferences::key(); integer ids are accepted too)
     *
     * @return array<int|string, ChatUserInterface> key => user; unknown keys are left out
     */
    public function usersByIds(array $ids): array
    {
        $keys    = array_values(array_unique(array_map(strval(...), $ids)));
        $request = $this->requests?->getMainRequest();
        if (!$request instanceof Request) {
            return $this->loadUsers($keys);
        }
        $memo    = $request->attributes->get(self::MEMO, []);
        $missing = array_values(array_filter($keys, static fn (string $key): bool => !array_key_exists('user:' . $key, $memo)));
        if ($missing !== []) {
            $loaded = $this->loadUsers($missing);
            foreach ($missing as $key) {
                $memo['user:' . $key] = $loaded[$key] ?? false;
            }
            $request->attributes->set(self::MEMO, $memo);
        }
        $users = [];
        foreach ($keys as $key) {
            if ($memo['user:' . $key] instanceof ChatUserInterface) {
                $users[$key] = $memo['user:' . $key];
            }
        }

        return $users;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<int|string, ChatUserInterface>
     */
    private function loadUsers(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        if ($this->directory instanceof ChatUserReferenceDirectoryInterface) {
            $found = $this->directory->findByReferences($keys);
        } else {
            // Integer ids only: a canonical decimal key (ChatUserReferences::isCanonicalId()) is an id; anything else is unknown.
            $ids   = array_values(array_filter($keys, ChatUserReferences::isCanonicalId(...)));
            $found = $ids === [] ? [] : $this->directory->findByIds(array_map(intval(...), $ids));
        }
        $wanted = array_fill_keys($keys, true);
        $users  = [];
        foreach ($found as $user) {
            $key = ChatUserReferences::key($user);
            if ($key !== null && isset($wanted[$key]) && in_array($key, $keys, true)) {
                $users[$key] = $user;
            }
        }

        return $users;
    }

    /**
     * A memo key part for a user that no other user can share: the key's length, then the key.
     */
    private function memoKey(ChatUserInterface $user): string
    {
        $key = (string) ChatUserReferences::key($user);

        return strlen($key) . '#' . $key;
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

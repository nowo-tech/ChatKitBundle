<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Realtime;

use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Psr\Cache\CacheItemPoolInterface;

use function is_array;

/**
 * Who receives realtime events of a conversation — the same people ChatVoter lets VIEW it: both people of a
 * direct thread, the owner and every support agent for a support thread, the channel members. Unreachable
 * accounts never receive anything.
 *
 * The id list is cached for 30 s per conversation, so a channel message, read or typing ping does not re-query
 * every member. Trade-off, by design: someone who loses access may still receive that conversation's events
 * for up to 30 s; every API read re-checks access immediately.
 */
final readonly class ChatRecipients
{
    private const int TTL_SECONDS = 30;

    /**
     * Builds the resolver from the cache pool, the user directory, the channel provider and the access checks.
     */
    public function __construct(
        private CacheItemPoolInterface $cache,
        private ChatDirectoryInterface $directory,
        private ChatChannelProviderInterface $channels,
        private ChatAccess $access,
    ) {
    }

    /**
     * Returns the reachable users who receive the conversation's realtime events (cached for 30 s).
     *
     * @param ChatConversation $conversation The conversation
     *
     * @return list<ChatUserInterface> The recipients
     */
    public function of(ChatConversation $conversation): array
    {
        $item = $this->cache->getItem('nowo_chat_recipients_' . $conversation->getUuid());
        if ($item->isHit() && is_array($ids = $item->get())) {
            return $ids === [] ? [] : $this->directory->findByIds(array_values(array_map(intval(...), $ids)));
        }
        $recipients = $this->compute($conversation);
        $this->cache->save($item->set(array_map(static fn (ChatUserInterface $user): int => (int) $user->getId(), $recipients))->expiresAfter(self::TTL_SECONDS));

        return $recipients;
    }

    /**
     * Drops the cached recipients of a conversation so the next event recomputes them. Call it when the application
     * revokes access to a known conversation (e.g. removes someone from a project channel); otherwise a revoked user
     * may still receive that conversation's realtime events for up to 30 seconds.
     *
     * @param ChatConversation $conversation The conversation whose recipients changed
     */
    public function forget(ChatConversation $conversation): void
    {
        $this->cache->deleteItem('nowo_chat_recipients_' . $conversation->getUuid());
    }

    /**
     * @return list<ChatUserInterface>
     */
    private function compute(ChatConversation $conversation): array
    {
        if ($conversation->isDirect()) {
            $candidates = $this->directory->findByIds($conversation->directUserIds());
        } elseif ($conversation->isSupport()) {
            $candidates = [...$this->directory->supportAgents(), ...array_filter([$conversation->getSupportOwner()])];
        } else {
            $channel    = $this->access->channel((string) $conversation->getChannelKey());
            $candidates = $channel instanceof ChatChannel ? $this->channels->members($channel) : [];
        }

        $unique = [];
        foreach ($candidates as $user) {
            if ($this->access->isReachable($user)) {
                $unique[(int) $user->getId()] = $user;
            }
        }

        return array_values($unique);
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Digest;

use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Queues one delayed digest check per recipient: further messages within the delay do not queue more.
 * Needs Messenger (the delay); without it, or with `digest.enabled: false`, nothing is scheduled.
 */
final readonly class ChatDigestScheduler
{
    /**
     * Builds the scheduler from the cache (pending markers), the Messenger bus and the digest settings.
     *
     * @param MessageBusInterface|null $bus Bus for the delayed check; null disables scheduling
     * @param bool $enabled Whether the digest is enabled
     * @param int $delayMinutes Delay before the unread check
     */
    public function __construct(
        private CacheItemPoolInterface $cache,
        private ?MessageBusInterface $bus = null,
        private bool $enabled = false,
        private int $delayMinutes = 15,
    ) {
    }

    /**
     * Dispatches one delayed digest check per recipient who has none pending.
     *
     * @param list<ChatUserInterface> $recipients Users who just received a message
     */
    public function schedule(array $recipients): void
    {
        if (!$this->enabled || !$this->bus instanceof MessageBusInterface) {
            return;
        }
        foreach ($recipients as $user) {
            $pending = $this->cache->getItem('nowo_chat_digest_pending_' . hash('xxh128', $user->getChatPublicId()));
            if ($pending->isHit()) {
                continue;
            }
            $this->cache->save($pending->set(true)->expiresAfter($this->delayMinutes * 60));
            $this->bus->dispatch(new SendChatDigestMessage((int) $user->getId()), [new DelayStamp($this->delayMinutes * 60_000)]);
        }
    }
}

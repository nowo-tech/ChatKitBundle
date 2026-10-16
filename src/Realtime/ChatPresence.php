<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Realtime;

use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

use function is_array;
use function is_int;

/**
 * Presence from tab heartbeats (every 30 s), kept only in the cache with a short TTL — no presence history is
 * stored. "Online" = a heartbeat within the window and the user shows presence; "app open" = a visible tab
 * within the window (Web Push is skipped then).
 */
final readonly class ChatPresence
{
    public const int WINDOW_SECONDS = 60;

    /**
     * Builds the presence tracker from the cache pool and the settings repository (presence visibility).
     *
     * @param int $ttl Cache lifetime of a heartbeat, in seconds
     * @param ClockInterface $clock Clock used for heartbeat timestamps
     */
    public function __construct(
        private CacheItemPoolInterface $cache,
        private ChatUserSettingsRepository $settings,
        private int $ttl = 90,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * Records a tab heartbeat for the user (and the "visible" timestamp when the tab is visible).
     *
     * @param ChatUserInterface $user The user whose tab is alive
     * @param bool $visible Whether the tab is currently visible
     */
    public function heartbeat(ChatUserInterface $user, bool $visible): void
    {
        $item          = $this->cache->getItem($this->key($user));
        $now           = $this->clock->now()->getTimestamp();
        $state         = $item->isHit() && is_array($item->get()) ? $item->get() : [];
        $state['seen'] = $now;
        if ($visible) {
            $state['visible'] = $now;
        }
        $this->cache->save($item->set($state)->expiresAfter($this->ttl));
    }

    /**
     * Drops the user's presence entry (e.g. on logout), so they appear offline at once.
     *
     * @param ChatUserInterface $user The user to forget
     */
    public function forget(ChatUserInterface $user): void
    {
        $this->cache->deleteItem($this->key($user));
    }

    /**
     * One cache round trip and one settings query for many users.
     *
     * @param list<ChatUserInterface> $users The users to check
     *
     * @return array<string, array{online: bool, appOpen: bool}> keyed by public id
     */
    public function statusOf(array $users): array
    {
        if ($users === []) {
            return [];
        }
        $byKey = [];
        foreach ($users as $user) {
            $byKey[$this->key($user)] = $user;
        }
        $settings = $this->settings->forUsers(array_values($byKey));
        $now      = $this->clock->now()->getTimestamp();
        $status   = [];
        foreach ($this->cache->getItems(array_keys($byKey)) as $key => $item) {
            $user                             = $byKey[$key];
            $state                            = $item->isHit() ? $item->get() : null;
            $seen                             = is_array($state) && is_int($state['seen'] ?? null) ? $state['seen'] : null;
            $visible                          = is_array($state) && is_int($state['visible'] ?? null) ? $state['visible'] : null;
            $status[$user->getChatPublicId()] = [
                'online'  => $settings[(int) $user->getId()]->isPresenceVisible() && $seen !== null && $now - $seen <= self::WINDOW_SECONDS,
                'appOpen' => $visible !== null && $now - $visible <= self::WINDOW_SECONDS,
            ];
        }

        return $status;
    }

    private function key(ChatUserInterface $user): string
    {
        return 'nowo_chat_presence_' . hash('xxh128', $user->getChatPublicId());
    }
}

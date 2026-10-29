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
 * stored. "Online" = a heartbeat within the window, the user shows presence and is not invisible; "app open" = a
 * visible tab within the window (Web Push is skipped then).
 *
 * Status (1.10) as others see it: `offline` unless online, else the status the user picked (busy, away, dnd), or
 * `away` when they picked available and their last heartbeat said idle (auto-away). An invisible user is `offline`
 * and shows no custom text, so the real state never leaks.
 */
final readonly class ChatPresence
{
    public const int WINDOW_SECONDS = 60;

    /** Statuses others may see. */
    public const array VISIBLE_STATUSES = ['available', 'busy', 'away', 'dnd', 'offline'];

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
     * @param bool $idle Whether the user has been idle for the auto-away delay (every tab of the browser)
     */
    public function heartbeat(ChatUserInterface $user, bool $visible, bool $idle = false): void
    {
        $item          = $this->cache->getItem($this->key($user));
        $now           = $this->clock->now()->getTimestamp();
        $state         = $item->isHit() && is_array($item->get()) ? $item->get() : [];
        $state['seen'] = $now;
        $state['idle'] = $idle;
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
     * @return array<string, array{online: bool, appOpen: bool, status: string, text: string|null, emoji: string|null}> keyed by public id
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
        $clock    = $this->clock->now();
        $now      = $clock->getTimestamp();
        $status   = [];
        foreach ($this->cache->getItems(array_keys($byKey)) as $key => $item) {
            $user        = $byKey[$key];
            $preferences = $settings[(int) $user->getId()];
            $state       = $item->isHit() ? $item->get() : null;
            $seen        = is_array($state) && is_int($state['seen'] ?? null) ? $state['seen'] : null;
            $visible     = is_array($state) && is_int($state['visible'] ?? null) ? $state['visible'] : null;
            $picked      = $preferences->getStatus($clock);
            $invisible   = $picked === 'invisible';
            $online      = !$invisible && $preferences->isPresenceVisible() && $seen !== null && $now - $seen <= self::WINDOW_SECONDS;
            $shown       = match (true) {
                !$online                                                                          => 'offline',
                $picked === 'available' && is_array($state) && true === ($state['idle'] ?? false) => 'away',
                default                                                                           => $picked,
            };
            $status[$user->getChatPublicId()] = [
                'online'  => $online,
                'appOpen' => $visible !== null && $now - $visible <= self::WINDOW_SECONDS,
                'status'  => $shown,
                'text'    => $invisible ? null : $preferences->getStatusText($clock),
                'emoji'   => $invisible ? null : $preferences->getStatusEmoji($clock),
            ];
        }

        return $status;
    }

    /**
     * What a viewer sees of a status: viewers who hide their own presence see nobody's (reciprocal), but still the
     * custom text.
     *
     * @param array{online: bool, appOpen: bool, status: string, text: string|null, emoji: string|null}|null $status One entry of statusOf(); null = unknown
     * @param bool $viewerShowsPresence Whether the viewer shows their own presence
     *
     * @return array{status: string, text: string|null, emoji: string|null}
     */
    public static function forViewer(?array $status, bool $viewerShowsPresence): array
    {
        return [
            'status' => $viewerShowsPresence ? $status['status'] ?? 'offline' : 'offline',
            'text'   => $status['text'] ?? null,
            'emoji'  => $status['emoji'] ?? null,
        ];
    }

    private function key(ChatUserInterface $user): string
    {
        return 'nowo_chat_presence_' . hash('xxh128', $user->getChatPublicId());
    }
}

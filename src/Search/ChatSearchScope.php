<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

/**
 * 1.37: what one viewer may read now, as SQL values: the viewer's user reference (direct conversations through the
 * viewer's participant rows, the viewer's own support tickets), whether every support ticket is readable (a support
 * agent), and the channel keys the host's provider grants now. Built per search, never kept.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatSearchScope
{
    /**
     * @param int|string|null $viewer SQL value of the viewer's user reference (null: no direct or own support conversation)
     * @param bool $supportAgent Whether every support ticket is readable
     * @param list<string> $channelKeys Keys of the channels the viewer may use now (exact keys)
     */
    public function __construct(
        public int|string|null $viewer,
        public bool $supportAgent,
        public array $channelKeys,
    ) {
    }
}

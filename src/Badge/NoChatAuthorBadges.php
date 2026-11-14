<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Badge;

use Nowo\ChatKitBundle\Contract\ChatAuthorBadgeProviderInterface;
use Nowo\ChatKitBundle\Model\ChatAuthorBadge;
use Nowo\ChatKitBundle\Model\ChatAuthorRef;

/**
 * 1.29: the default {@see ChatAuthorBadgeProviderInterface}: no badge for anyone. The bundle never calls it (ChatAuthorBadges
 * sees it is the default and asks nothing). Stateless.
 */
final readonly class NoChatAuthorBadges implements ChatAuthorBadgeProviderInterface
{
    /**
     * No badges.
     *
     * @param list<ChatAuthorRef> $authors Ignored
     *
     * @return array<string, list<ChatAuthorBadge>> Always empty
     */
    public function badgesForMany(array $authors): array
    {
        return [];
    }
}

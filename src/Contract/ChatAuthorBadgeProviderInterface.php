<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatAuthorBadge;
use Nowo\ChatKitBundle\Model\ChatAuthorRef;

/**
 * 1.29 (Beacon spec 234): the badges shown next to a message author's name — in the dock, the full page and the message
 * API (`authorBadges`), also in realtime events. The application aliases this interface; the default gives no badge,
 * asks nothing, and every output stays exactly as in 1.28.
 *
 * Framework-free (REQ-ARCH-001 rule 3): authors come as {@see ChatAuthorRef} (the stored user key and the public id),
 * badges go back as {@see ChatAuthorBadge} values (plain text, an allow-listed variant); never HTML. The bundle asks
 * once per response for the authors it shows and keeps the answer for that request only. Must not run a query per
 * author. Keep no state across requests (FrankenPHP worker mode).
 *
 * Badges are public: every reader of a message sees the same ones. Decide them from the author alone, never from the
 * signed-in user (no "visible to admins only" badge, no badge that depends on who is looking): the realtime payload of
 * a message is built once, in the author's request (or the one that edits or deletes it), and sent as is to every
 * recipient. A message removed by a moderator keeps its author's badges on the tombstone.
 */
interface ChatAuthorBadgeProviderInterface
{
    /**
     * The badges of several authors.
     *
     * @param list<ChatAuthorRef> $authors Distinct authors (by reference), never empty
     *
     * @return array<string, list<ChatAuthorBadge>> By {@see ChatAuthorRef::$reference}, compared byte for byte; a missing
     *                                              author has no badge, keys that were not asked are ignored, anything
     *                                              but a ChatAuthorBadge is dropped
     */
    public function badgesForMany(array $authors): array;
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Since 1.16: the URL of a person's profile picture. The application aliases this interface; the default returns
 * null for everyone (the initials bubble).
 *
 * The URL MUST be stable for at least the life of a page, and MUST be access-checked when the browser fetches it
 * (no bearer signature in it): it travels in realtime payloads and stays in the dock's state while the page is open.
 * The bundle accepts `https:` URLs and same-origin paths starting with a single `/`; anything else is dropped
 * (AvatarUrlPolicy).
 */
interface ChatAvatarUrlResolverInterface
{
    /**
     * The URL of one person's picture. The bundle itself never calls it (it batches through urlsFor()).
     *
     * @param ChatUserInterface $user The person
     * @param int $size Largest CSS size in px the picture is displayed at (the host picks a variant, e.g. ≥ 2× for HiDPI); 1 to 512
     *
     * @return string|null The URL, or null for the initials
     */
    public function urlFor(ChatUserInterface $user, int $size): ?string;

    /**
     * The URLs of several people: one call per response. Must not run a query per user.
     *
     * @param list<ChatUserInterface> $users Distinct users (by public id), never empty
     * @param int $size Largest CSS size in px the pictures are displayed at; 1 to 512
     *
     * @return array<string, string|null> Public id (getChatPublicId()) => URL or null; a missing key means null,
     *                                    unknown keys are ignored
     */
    public function urlsFor(array $users, int $size): array;
}

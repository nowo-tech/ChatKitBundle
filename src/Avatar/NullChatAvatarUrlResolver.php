<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Avatar;

use Nowo\ChatKitBundle\Contract\ChatAvatarUrlResolverInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Default (1.16): no profile pictures, every bubble shows the initials.
 */
final class NullChatAvatarUrlResolver implements ChatAvatarUrlResolverInterface
{
    /**
     * No picture.
     *
     * @param ChatUserInterface $user Ignored
     * @param int $size Ignored
     *
     * @return string|null Always null
     */
    public function urlFor(ChatUserInterface $user, int $size): ?string
    {
        return null;
    }

    /**
     * No pictures.
     *
     * @param list<ChatUserInterface> $users Ignored
     * @param int $size Ignored
     *
     * @return array<string, string|null> Always empty
     */
    public function urlsFor(array $users, int $size): array
    {
        return [];
    }
}

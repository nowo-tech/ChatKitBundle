<?php

declare(strict_types=1);

namespace App\Chat;

use App\Entity\User;
use Nowo\ChatKitBundle\Contract\ChatAvatarUrlResolverInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Demo profile pictures (ChatKit 1.16): Alice and Bob have one under /avatars, Carol's points at a missing file (the
 * dock falls back to her initials), the support agent has none.
 */
final class DemoAvatarUrlResolver implements ChatAvatarUrlResolverInterface
{
    private const array PICTURES = [
        'alice' => '/avatars/alice.svg',
        'bob'   => '/avatars/bob.svg',
        'carol' => '/does-not-exist.webp',
    ];

    public function urlFor(ChatUserInterface $user, int $size): ?string
    {
        return $user instanceof User ? (self::PICTURES[$user->getUserIdentifier()] ?? null) : null;
    }

    public function urlsFor(array $users, int $size): array
    {
        $urls = [];
        foreach ($users as $user) {
            $urls[$user->getChatPublicId()] = $this->urlFor($user, $size);
        }

        return $urls;
    }
}

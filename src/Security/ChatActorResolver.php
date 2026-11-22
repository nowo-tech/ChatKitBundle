<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Security;

use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function preg_match;
use function strlen;

/**
 * 2.2 (DDD-host checklist B7/B8): turns the user a host query port receives — a ChatUserInterface, or the user's public
 * id (ChatUserInterface::getChatPublicId()) — into the chat user, through ChatDirectoryInterface::findByPublicId().
 *
 * House rule LQ: the directory's answer is kept only when its public id is exactly the one asked for, byte for byte (a
 * directory that folds case, accents or padding never hands back a look-alike user). A public id that cannot be one
 * (empty, longer than 255 bytes, invalid UTF-8, control or format characters, whitespace at either end) is unknown without
 * asking the directory. Unknown and look-alike ids give the same answer: null. Stateless; nothing is kept between calls
 * (FrankenPHP worker mode, kernel reset false). Internal API.
 */
final readonly class ChatActorResolver
{
    /** Longest public id the ports look up. */
    public const int MAX_PUBLIC_ID_LENGTH = 255;

    /**
     * @param ChatDirectoryInterface $directory The application's users
     */
    public function __construct(
        private ChatDirectoryInterface $directory,
    ) {
    }

    /**
     * The chat user for a user object or a public id; null when the id is unusable, unknown or answered with a look-alike.
     *
     * @param ChatUserInterface|string $user The user, or its public id
     *
     * @return ChatUserInterface|null The user, or null
     */
    public function resolve(ChatUserInterface|string $user): ?ChatUserInterface
    {
        if ($user instanceof ChatUserInterface) {
            return $user;
        }
        if (!self::isUsable($user)) {
            return null;
        }
        $found = $this->directory->findByPublicId($user);

        return $found instanceof ChatUserInterface && $found->getChatPublicId() === $user ? $found : null;
    }

    /**
     * Whether a string can be a public id (checked before any lookup).
     *
     * @param string $publicId The candidate
     *
     * @return bool True when it may be looked up
     */
    public static function isUsable(string $publicId): bool
    {
        return $publicId !== '' && strlen($publicId) <= self::MAX_PUBLIC_ID_LENGTH
            && preg_match('/\A[^\s\p{Z}\p{Cc}\p{Cf}\p{Cs}\p{Co}](?:[^\p{Cc}\p{Cf}\p{Cs}\p{Co}\p{Zl}\p{Zp}]*[^\s\p{Z}\p{Cc}\p{Cf}\p{Cs}\p{Co}])?\z/u', $publicId) === 1;
    }
}

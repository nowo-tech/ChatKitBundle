<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use LogicException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 2.0: how the events turn a user into the scalar the host stores (internal).
 *
 * The reference is the key the chat keeps for the user and the host can pass to `getReference()` or compare with its own
 * user id: the integer id as a string in the `relation` and `id` modes, the ChatUserReferenceInterface reference in the
 * `string` mode (ChatUserReferences::key()).
 */
final class EventRefs
{
    /**
     * The key of a user.
     *
     * @param ChatUserInterface $user The user
     *
     * @throws LogicException When the user has no id yet (an event never carries one)
     *
     * @return string The key (the host user id as a string)
     */
    public static function user(ChatUserInterface $user): string
    {
        return ChatUserReferences::key($user) ?? throw new LogicException('A chat event user needs an id.');
    }

    /**
     * The key of an optional user.
     *
     * @param ChatUserInterface|null $user The user, or null
     *
     * @return string|null The key; null for no user
     */
    public static function optional(?ChatUserInterface $user): ?string
    {
        return $user instanceof ChatUserInterface ? self::user($user) : null;
    }

    /**
     * The primary key of a stored row.
     *
     * @param int|null $id The id of the entity
     *
     * @throws LogicException When the row has no id yet (an event never carries one)
     *
     * @return int The id
     */
    public static function id(?int $id): int
    {
        return $id ?? throw new LogicException('A chat event row needs an id.');
    }
}

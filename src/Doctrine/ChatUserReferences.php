<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\ORM\QueryBuilder;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.24: how the bundle's tables point at users (`doctrine.user_reference`).
 *
 * - `relation` (default, 1.x behaviour): Doctrine relations to `user_class`, mapped through
 *   `doctrine.orm.resolve_target_entities`, with foreign keys to the host user table.
 * - `id`: no relation and no foreign key — each user column (`author_id`, `user_id`, …) holds the user's id as a string
 *   (ChatUserInterface::getId(), at most REFERENCE_LENGTH characters), and the users are loaded through
 *   ChatDirectoryInterface::findByIds() after the rows (UserReferenceListener). The user class no longer has to be a
 *   Doctrine entity, and deleting a user no longer cascades: call ChatPersonalData::erase() from the account deletion.
 *
 * Queries of the bundle ask this service for the field to compare and the value to bind, so the same DQL runs in both
 * modes. Internal API (the field names in id mode are `<relation>Ref`).
 */
final readonly class ChatUserReferences
{
    public const string RELATION = 'relation';

    public const string ID = 'id';

    /** Longest user reference stored in id mode. */
    public const int REFERENCE_LENGTH = 64;

    /**
     * @param string $mode `relation` or `id`
     */
    public function __construct(
        private string $mode = self::RELATION,
    ) {
    }

    /**
     * Whether users are referenced by id (no Doctrine relation).
     *
     * @return bool True in `id` mode
     */
    public function isIdMode(): bool
    {
        return $this->mode === self::ID;
    }

    /**
     * The DQL path to compare with a user (`p.user` or, in id mode, `p.userRef`).
     *
     * @param string $alias DQL alias of the bundle entity
     * @param string $relation Name of the user relation (`user`, `author`, `supportOwner`, …)
     *
     * @return string The DQL path
     */
    public function field(string $alias, string $relation): string
    {
        return $alias . '.' . $relation . ($this->isIdMode() ? 'Ref' : '');
    }

    /**
     * The DQL expression of the user's id (`IDENTITY(p.user)` or, in id mode, `p.userRef`).
     *
     * @param string $alias DQL alias of the bundle entity
     * @param string $relation Name of the user relation
     *
     * @return string The DQL expression
     */
    public function identity(string $alias, string $relation): string
    {
        return $this->isIdMode() ? $alias . '.' . $relation . 'Ref' : 'IDENTITY(' . $alias . '.' . $relation . ')';
    }

    /**
     * The value to bind for a user: the user itself (relation) or its reference (id mode).
     *
     * @param ChatUserInterface $user The user
     *
     * @return ChatUserInterface|string The parameter value
     */
    public function value(ChatUserInterface $user): ChatUserInterface|string
    {
        return $this->isIdMode() ? (string) self::reference($user) : $user;
    }

    /**
     * The value to bind for a user id in the bundle's raw SQL (`user_id`, `author_id` columns).
     *
     * @param int $userId The user id
     *
     * @return int|string The parameter value
     */
    public function sqlValue(int $userId): int|string
    {
        return $this->isIdMode() ? (string) $userId : $userId;
    }

    /**
     * Values to bind for a list of user ids (`IN (:ids)` on a user column): the ids, or (id mode) their references.
     *
     * @param list<int> $ids User ids
     *
     * @return list<int|string> The parameter values
     */
    public function ids(array $ids): array
    {
        return $this->isIdMode() ? array_map(strval(...), $ids) : $ids;
    }

    /**
     * Joins and selects a user relation to load the users with the rows (relation mode); nothing in id mode, where
     * UserReferenceListener loads them through the directory after the rows.
     *
     * @param QueryBuilder $qb The query
     * @param string $path The relation path (`m.author`)
     * @param string $alias The join alias
     * @param bool $inner Inner join (rows without a user are left out) instead of a left join
     */
    public function join(QueryBuilder $qb, string $path, string $alias, bool $inner = false): void
    {
        if ($this->isIdMode()) {
            return;
        }
        ($inner ? $qb->innerJoin($path, $alias) : $qb->leftJoin($path, $alias))->addSelect($alias);
    }

    /**
     * `findBy()` criteria on a user relation.
     *
     * @param string $relation Name of the user relation
     * @param ChatUserInterface $user The user
     *
     * @return array<string, ChatUserInterface|string> The criteria
     */
    public function criteria(string $relation, ChatUserInterface $user): array
    {
        return [$relation . ($this->isIdMode() ? 'Ref' : '') => $this->value($user)];
    }

    /**
     * The reference stored for a user in id mode: its id as a string; null for no user (or one not persisted yet).
     *
     * @param ChatUserInterface|null $user The user
     *
     * @return string|null The reference
     */
    public static function reference(?ChatUserInterface $user): ?string
    {
        $id = $user?->getId();

        return $id === null ? null : (string) $id;
    }
}

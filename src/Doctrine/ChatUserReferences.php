<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use LogicException;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ChatUserReference;
use Nowo\ChatKitBundle\Model\ChatUserReferenceInterface;

use function array_map;
use function array_values;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * 1.24: how the bundle's tables point at users (`doctrine.user_reference`).
 *
 * - `relation` (default, 1.x behaviour): Doctrine relations to `user_class`, mapped through
 *   `doctrine.orm.resolve_target_entities`, with foreign keys to the host user table.
 * - `id`: no relation and no foreign key — each user column (`author_id`, `user_id`, …) holds the user's reference as a
 *   string (ChatUserInterface::getId(), or 1.27 ChatUserReferenceInterface::getChatUserReference() for string
 *   identifiers; at most REFERENCE_LENGTH characters), and the users are loaded through the directory after the rows
 *   (UserReferenceListener). The user class no longer has to be a
 *   Doctrine entity, and deleting a user no longer cascades: call ChatPersonalData::erase() from the account deletion.
 *
 * Queries of the bundle ask this service for the field to compare and the value to bind, so the same DQL runs in both
 * modes. Internal API (the field names in id mode are `<relation>Ref`).
 */
final readonly class ChatUserReferences
{
    public const string RELATION = 'relation';

    public const string ID = 'id';

    /**
     * 1.27: `id` with string references (ChatUserReferenceInterface): every chat user is keyed by its string reference,
     * the direct pairs keep both references (`direct_user_low` / `direct_user_high`), the user columns must be binary NO
     * PAD on MySQL / MariaDB (checked once per process before the first query by user, StringReferenceSchemaGuard).
     */
    public const string STRING = 'string';

    /** Longest user reference stored in id mode. */
    public const int REFERENCE_LENGTH = 64;

    /**
     * @param string $mode `relation` or `id`
     */
    public function __construct(
        private string $mode = self::RELATION,
        private ?StringReferenceSchemaGuard $guard = null,
    ) {
    }

    /**
     * Whether users are referenced by id (no Doctrine relation): the `id` and (1.27) `string` modes.
     *
     * @return bool True in `id` or `string` mode
     */
    public function isIdMode(): bool
    {
        return $this->mode === self::ID || $this->mode === self::STRING;
    }

    /**
     * 1.27: whether chat users are keyed by string references (ChatUserReferenceInterface).
     *
     * @return bool True in `string` mode
     */
    public function isStringMode(): bool
    {
        return $this->mode === self::STRING;
    }

    /**
     * 1.27 (audit OJ-L1): refuses a user of the other kind — a ChatUserReferenceInterface user outside `string` mode, or
     * an integer-id user in it — so a reference `"42"` can never meet the integer id 42 in one host.
     *
     * @param ChatUserInterface $user The user
     *
     * @throws LogicException When the user does not fit the mode
     */
    public function assertKind(ChatUserInterface $user): void
    {
        if (!$this->fitsKind($user)) {
            $message = $this->isStringMode()
                ? sprintf('nowo_chat_kit: doctrine.user_reference is "string": every chat user must implement %s (%s does not).', ChatUserReferenceInterface::class, $user::class)
                : sprintf('nowo_chat_kit: %s implements %s, which needs doctrine.user_reference "string".', $user::class, ChatUserReferenceInterface::class);

            throw new LogicException($message);
        }
    }

    /**
     * 1.27: whether the user is of the mode's kind (always in `relation` mode; placeholders of gone users always).
     *
     * @param ChatUserInterface $user The user
     *
     * @return bool True when usable in this mode
     */
    public function fitsKind(ChatUserInterface $user): bool
    {
        return $this->mode === self::RELATION || $user instanceof UnresolvedChatUser
            || $this->isStringMode() === $user instanceof ChatUserReferenceInterface;
    }

    /**
     * 1.27 (audit OJ-M1): before a query by user in `string` mode, the user columns must compare byte for byte.
     */
    private function guarded(): void
    {
        if ($this->isStringMode()) {
            $this->guard?->assertReady();
        }
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
        $this->guarded();

        return $this->isIdMode() ? (string) self::reference($user) : $user;
    }

    /**
     * The value to bind for a user key in the bundle's raw SQL and list parameters (`user_id`, `author_id` columns): the
     * integer id in relation mode, the reference string in id mode.
     *
     * @param int|string $key A user key (self::key(); an integer id is accepted too)
     *
     * @return int|string The parameter value
     */
    public function sqlValue(int|string $key): int|string
    {
        $this->guarded();

        return $this->isIdMode() ? (string) $key : (int) $key;
    }

    /**
     * Values to bind for a list of user keys (`IN (:ids)` on a user column).
     *
     * @param list<int|string> $keys User keys
     *
     * @return list<int|string> The parameter values
     */
    public function ids(array $keys): array
    {
        return array_values(array_map($this->sqlValue(...), $keys));
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
     * The reference stored for a user in id mode: 1.27 its ChatUserReferenceInterface reference (checked against the
     * ChatUserReference format rule), else its id as a string; null for no user (or one not persisted yet).
     *
     * @param ChatUserInterface|null $user The user
     *
     * @throws InvalidArgumentException When a ChatUserReferenceInterface reference breaks the format rule
     *
     * @return string|null The reference
     */
    public static function reference(?ChatUserInterface $user): ?string
    {
        if ($user instanceof ChatUserReferenceInterface) {
            return ChatUserReference::assertValid($user->getChatUserReference());
        }
        $id = $user?->getId();

        return $id === null ? null : (string) $id;
    }

    /**
     * 1.27: the key that identifies a user in memory and in the bundle's user columns (the same string the id mode
     * stores; the id as a string otherwise). Compare keys with `===` only (REQ-SEC-006 item 7).
     *
     * @param ChatUserInterface $user The user
     *
     * @return string|null The key; null for a user without an id (never reachable)
     */
    public static function key(ChatUserInterface $user): ?string
    {
        return self::reference($user);
    }

    /**
     * 1.27: a user key as the integer id when it is one (a canonical decimal: what 1.26 used), else the string reference;
     * keeps the lists and messages of integer-id hosts exactly as before.
     *
     * @param int|string $key A user key
     *
     * @return int|string The id or the reference
     */
    public static function idOrKey(int|string $key): int|string
    {
        return is_string($key) && self::isCanonicalId($key) ? (int) $key : $key;
    }

    /**
     * 1.27 (audit OJ-L2): whether a key is an integer id as the bundle writes it: a canonical decimal of at most 18
     * digits (no sign, no leading zero; always within PHP_INT_MAX). The one rule for every place that turns keys into ids.
     *
     * @param string $key A user key
     *
     * @return bool True for a canonical integer id
     */
    public static function isCanonicalId(string $key): bool
    {
        return preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D', $key) === 1;
    }

    /**
     * 1.27: whether two users are the same person (equal keys, byte for byte; never two users without a key).
     *
     * @param ChatUserInterface|null $a One user
     * @param ChatUserInterface|null $b The other
     *
     * @return bool True for the same user
     */
    public static function same(?ChatUserInterface $a, ?ChatUserInterface $b): bool
    {
        if (!$a instanceof ChatUserInterface || !$b instanceof ChatUserInterface) {
            return false;
        }
        $key = self::key($a);

        return $key !== null && $key === self::key($b);
    }
}

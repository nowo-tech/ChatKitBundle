<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

use function array_keys;
use function hash_hmac;
use function implode;
use function in_array;
use function is_string;
use function sprintf;
use function substr;

/**
 * 1.27 (audit OJ-M1, REQ-SEC-006; `doctrine.user_reference: string` only): fail closed when the user reference columns
 * still fold. On MySQL / MariaDB the bundle checks (information_schema) that every user column of its tables has a
 * collation that compares byte for byte without padding: `utf8mb4_0900_bin` on MySQL 8 and later,
 * `utf8mb4_nopad_bin` on MariaDB, and `utf8mb4_bin` on MySQL 5.7 only — 1.27.1 (REQ-SEC-006): `utf8mb4_bin` is PAD
 * SPACE on MySQL 8 and on MariaDB, where `'bob '` equals `'bob'`, so it is no longer accepted there; 5.7 has no NO PAD
 * collation at all, and there the ChatUserReference format rule, which refuses leading and trailing spaces, closes the
 * gap. Until the `ALTER TABLE` of UPGRADING.md has run, every query and write by user throws a LogicException
 * instead: a folding column would let `bob` read `Bob`'s rows, and a unique-key violation between look-alikes would
 * close the entity manager (a denial of service in a kept-alive worker). PostgreSQL and SQLite compare exactly and are
 * never queried.
 *
 * Only a passed check is kept (a fact about the schema, not request data), per connection object, and only until the
 * next request / command / message boundary: ChatRequestBoundaryListener resets this service like the bundle's memos
 * (1.27.1, audit AOY-M2), so the schema is read once per request instead of once per query by user and a database
 * switched between two requests of the same worker is checked again. The pass says nothing more than "this Connection
 * object passed": it does not distinguish the same database name on another server, nor a `USE other_db` issued on the
 * connection inside one request (audit AOY-L2).
 *
 * 1.28 it also carries the look-alike rows seen in this request: a read by user that comes back with another person's
 * row behind a passed check (a migration rolled back, a column altered later) warns and degrades — the reader gets
 * null or unsaved defaults, as before — while a write that would insert a row for that same user into that same column
 * fails closed (ChatUserLookAlikeException), because that insert would clash with the look-alike's unique key inside
 * the flush and close the entity manager. The other tables, which may compare exactly, stay writable (audit PV-L1).
 * Neither the warning nor the exception carries a user reference: they name the column and a keyed hash of the
 * reference (audit PV-L3, REQ-OBS-001), enough to correlate lines without logging an e-mail address or a username.
 */
final class StringReferenceSchemaGuard implements ResetInterface
{
    /** Every collation this guard accepts on some platform; exactFor() is what it applies to one. */
    public const array EXACT = ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin', 'utf8mb4_bin', 'binary'];

    /** 1.28 (audit PV-D5): domain prefix of the hashed reference, so this HMAC under `kernel.secret` is never another one. */
    public const string HASH_DOMAIN = 'nowo_chat_lookalike|';

    /** @var WeakMap<Connection, true> Connections whose schema passed the check in this request. */
    private WeakMap $ready;

    /** @var array<string, true> 1.28: `column` NUL `user key` of the look-alike rows seen in this request. */
    private array $lookAlikes = [];

    /**
     * @param ManagerRegistry $doctrine Finds the manager (and connection) of the chat entities
     * @param LoggerInterface|null $logger Where the look-alike warnings go
     * @param string $secret Keys the hash of a reference in the warning and the exception (`kernel.secret`)
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $secret = '',
    ) {
        $this->ready = new WeakMap();
    }

    /**
     * Forgets the passed checks and the look-alike rows of the request (the next query of the next request checks
     * again). ChatRequestBoundaryListener calls this at every request, command and message boundary, and so does
     * `kernel.reset`.
     */
    public function reset(): void
    {
        // @igor-ignore - Schema fact (never request data), kept for one request and cleared at every boundary.
        $this->ready      = new WeakMap();
        $this->lookAlikes = [];
    }

    /**
     * 1.28: a read by user came back with another person's row, which only a folding user column can do. The read
     * degrades (null, or unsaved defaults) and this warns once per column and user key in the request: the settings
     * read runs on every page of the host (ChatDockExtension::config()) and inside the GDPR erasure, so it must never
     * answer 500. A write for the same user into the same column is then refused by assertNoLookAlike().
     *
     * @param string $where The column the row came from (`nowo_chat_participant.user_id`)
     * @param string $key The key of the user that was ASKED for (never the owner of the row that came back)
     */
    public function sawLookAlike(string $where, string $key): void
    {
        $seen = $where . "\0" . $key;
        if (isset($this->lookAlikes[$seen])) {
            return;
        }
        // @igor-ignore - Request data, cleared by reset() at every request / command / message boundary.
        $this->lookAlikes[$seen] = true;
        $this->logger?->warning('nowo_chat_kit: {column} compares user references loosely: a look-alike\'s row came back for the user reference with hash {reference_hash}. The chat ignores that row, and a write for that user into this column is refused. Run the ALTER TABLE of UPGRADING.md ("String user references").', [
            'column'         => $where,
            'reference_hash' => $this->hashOf($key),
        ]);
    }

    /**
     * 1.28: refuses the insert into `$where` that would follow a read which found a look-alike's row in that column,
     * because it would clash with that row's unique key inside the flush and close the entity manager (and, under
     * FrankenPHP with `kernel.reset` false, leave it closed for every later request of the worker).
     *
     * @param string $where The column the row is about to be written into (`nowo_chat_participant.user_id`)
     * @param string $key The user key a chat row is about to be written for
     *
     * @throws ChatUserLookAlikeException When a look-alike row was seen for this user in this column in this request
     */
    public function assertNoLookAlike(string $where, string $key): void
    {
        if (!isset($this->lookAlikes[$where . "\0" . $key])) {
            return;
        }

        throw new ChatUserLookAlikeException(sprintf('nowo_chat_kit: refusing to write a chat row for the user reference with hash %s: %s compares user references loosely and already holds a look-alike\'s row. Run the ALTER TABLE of UPGRADING.md ("String user references").', $this->hashOf($key), $where));
    }

    /**
     * 1.28 (audit PV-L3): what the warning and the exception show instead of a reference — the first 16 hex digits of
     * its HMAC-SHA256 under `kernel.secret`, over HASH_DOMAIN followed by the reference (audit PV-D5). The same
     * reference always gives the same hash in one installation, so log lines can be correlated, but the reference
     * cannot be read back or guessed from a list of e-mail addresses without the secret.
     *
     * @param string $key A user key
     *
     * @return string The hash
     */
    public function hashOf(string $key): string
    {
        return substr(hash_hmac('sha256', self::HASH_DOMAIN . $key, $this->secret), 0, 16);
    }

    /**
     * The collations that compare a user reference byte for byte on this platform (1.27.1, REQ-SEC-006).
     * `utf8mb4_bin` is PAD SPACE on MySQL 8 and on MariaDB, so only MySQL 5.7, which has no NO PAD collation, may use
     * it; there the ChatUserReference format rule refuses the trailing spaces that would fold.
     *
     * The `default` arm is the MySQL 5.7 one, and it is the least strict of the three (audit AOY-N1): it also catches
     * any MySQL-family server that carries no `mariadb` marker and whose version DBAL cannot read as >= 8.0.0 — a
     * fork, a proxy that rewrites the version banner, an unparseable string — so such a server is allowed
     * `utf8mb4_bin` although it may well pad like MySQL 8. There, what keeps the references exact is not the
     * collation but ChatUserReference::assertValid(), which refuses leading and trailing whitespace on every write.
     * Making an unrecognised platform fail closed instead is a 1.28 decision, not a patch one.
     *
     * @param AbstractPlatform $platform The database platform
     *
     * @return list<string> The accepted collation names
     */
    public static function exactFor(AbstractPlatform $platform): array
    {
        return match (true) {
            $platform instanceof MariaDBPlatform => ['utf8mb4_nopad_bin', 'binary'],
            $platform instanceof MySQL80Platform => ['utf8mb4_0900_bin', 'binary'],
            default                              => ['utf8mb4_bin', 'binary'],
        };
    }

    /**
     * Checks the user columns once per connection and request.
     *
     * @throws LogicException When a user column of the chat tables still folds
     */
    public function assertReady(): void
    {
        $manager = $this->doctrine->getManagerForClass(ChatConversation::class);
        if (!$manager instanceof EntityManagerInterface) {
            return;
        }
        $connection = $manager->getConnection();
        if (isset($this->ready[$connection])) {
            return;
        }
        $platform = $connection->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            // @igor-ignore - Schema fact of one request: PostgreSQL / SQLite compare exactly.
            $this->ready[$connection] = true;

            return;
        }
        $exact   = self::exactFor($platform);
        $folding = [];
        $missing = [];
        foreach (array_keys(UserReferenceListener::RELATIONS) as $class) {
            $metadata = $manager->getClassMetadata($class);
            $table    = $metadata->getTableName();
            $columns  = [];
            foreach (array_keys(UserReferenceListener::RELATIONS[$class]) as $relation) {
                $columns[] = $metadata->getColumnName($relation . 'Ref');
            }
            foreach (UserReferenceListener::EXTRA_FIELDS[$class] ?? [] as $column) {
                $columns[] = $column;
            }
            $found = [];
            foreach ($connection->fetchAllAssociative(
                'SELECT COLUMN_NAME AS c, COLLATION_NAME AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?)',
                [$table, $columns],
                [1 => ArrayParameterType::STRING],
            ) as $row) {
                $found[]   = (string) ($row['c'] ?? $row['C'] ?? '');
                $collation = $row['n'] ?? $row['N'] ?? null;
                if (!is_string($collation) || !in_array($collation, $exact, true)) {
                    $folding[] = $table . '.' . ($row['c'] ?? $row['C'] ?? '?') . ' (' . (is_string($collation) ? $collation : 'no collation') . ')';
                }
            }
            foreach ($columns as $column) {
                if (!in_array($column, $found, true)) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }
        if ($missing !== []) {
            throw new LogicException(sprintf('nowo_chat_kit: doctrine.user_reference "string" needs these columns, which the database does not have yet: %s. Run the migration of UPGRADING.md ("String user references").', implode(', ', $missing)));
        }
        if ($folding !== []) {
            throw new LogicException(sprintf('nowo_chat_kit: doctrine.user_reference "string" needs the user columns to compare byte for byte, but these still fold: %s. Accepted here: %s. Run the ALTER TABLE of UPGRADING.md ("String user references") before using the chat.', implode(', ', $folding), implode(', ', $exact)));
        }
        // @igor-ignore - Schema fact (never request data), cleared at every request boundary by reset().
        $this->ready[$connection] = true;
    }
}

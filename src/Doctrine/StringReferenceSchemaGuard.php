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
use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

use function array_keys;
use function implode;
use function in_array;
use function is_string;
use function sprintf;

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
 */
final class StringReferenceSchemaGuard implements ResetInterface
{
    /** Every collation this guard accepts on some platform; exactFor() is what it applies to one. */
    public const array EXACT = ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin', 'utf8mb4_bin', 'binary'];

    /** @var WeakMap<Connection, true> Connections whose schema passed the check in this request. */
    private WeakMap $ready;

    /**
     * @param ManagerRegistry $doctrine Finds the manager (and connection) of the chat entities
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
        $this->ready = new WeakMap();
    }

    /**
     * Forgets the passed checks (the next query of the next request checks again). ChatRequestBoundaryListener calls
     * this at every request, command and message boundary, and so does `kernel.reset`.
     */
    public function reset(): void
    {
        // @igor-ignore - Schema fact (never request data), kept for one request and cleared at every boundary.
        $this->ready = new WeakMap();
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

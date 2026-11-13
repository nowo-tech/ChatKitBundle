<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
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
 * still fold. On MySQL / MariaDB the bundle checks once per connection and database (information_schema) that every user column of its
 * tables has a binary NO PAD collation (`utf8mb4_0900_bin`, `utf8mb4_nopad_bin`; `utf8mb4_bin` on MySQL 5.7, whose
 * trailing spaces ChatUserReference refuses). Until the `ALTER TABLE` of UPGRADING.md has run, every query and write by
 * user throws a LogicException instead: a folding column would let `bob` read `Bob`'s rows, and a unique-key violation
 * between look-alikes would close the entity manager (a denial of service in a kept-alive worker). PostgreSQL and SQLite
 * compare exactly and are never queried.
 *
 * Only a passed check is kept (a fact about the schema, not request data), and 1.27.1 (audit AOJ2-L2) it is kept per
 * connection and per `DATABASE()`: a process that switches database at runtime (a multi-tenant host, a reconnect to
 * another schema) or whose migration was rolled back checks the new schema instead of trusting the old pass. reset()
 * forgets everything.
 */
final class StringReferenceSchemaGuard implements ResetInterface
{
    /** Collations that compare user references byte for byte. */
    public const array EXACT = ['utf8mb4_0900_bin', 'utf8mb4_nopad_bin', 'utf8mb4_bin', 'binary'];

    /** @var WeakMap<Connection, string> Connections whose current database passed the check (connection => database). */
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
     * Forgets a passed check (the next query checks again).
     */
    public function reset(): void
    {
        // @igor-ignore - Process-level schema fact (never request data); reset() and a failing check clear it.
        $this->ready = new WeakMap();
    }

    /**
     * Checks the user columns once per connection and database.
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
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // PostgreSQL / SQLite compare exactly: nothing to check and nothing to remember.
            return;
        }
        // The database this connection is on right now: `USE other_db` and a reconnect to another schema both change it,
        // and the columns of that schema have never been checked (audit AOJ2-L2). One cheap query saves eight below.
        $database = (string) $connection->fetchOne('SELECT DATABASE()');
        if (($this->ready[$connection] ?? null) === $database) {
            return;
        }
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
                if (!is_string($collation) || !in_array($collation, self::EXACT, true)) {
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
            throw new LogicException(sprintf('nowo_chat_kit: doctrine.user_reference "string" needs the user columns to compare byte for byte, but these still fold: %s. Run the ALTER TABLE of UPGRADING.md ("String user references") before using the chat.', implode(', ', $folding)));
        }
        // @igor-ignore - Process-level schema fact (never request data), cleared by reset().
        $this->ready[$connection] = $database;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Doctrine\TablePrefixListener;
use Nowo\ChatKitBundle\Entity\ChatMessage;

use function preg_match;
use function sprintf;

/**
 * 1.37: the full-text index of message search: its name (following `doctrine.table_prefix` /
 * `doctrine.table_name_prefix`), its state and the SQL that builds or drops it.
 *
 * - MySQL / MariaDB: `CREATE FULLTEXT INDEX … (body)`. {@see ChatSearchSchemaListener} also maps it, so
 *   `doctrine:migrations:diff` creates it and never drops it.
 * - PostgreSQL: a GIN expression index on `to_tsvector('<config>'::regconfig, body)`, built `CONCURRENTLY` (writes go on
 *   while it builds). Doctrine does not read expression indexes back, so a diff never drops it either.
 * - Other platforms: no index (the search uses `LIKE`).
 *
 * Stateless (FrankenPHP worker mode).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatSearchIndex
{
    /** The index name as mapped (`TablePrefixListener` rules give the physical name). */
    public const string MAPPED_NAME = 'idx_nowo_chat_message_body_fts';

    /** Default PostgreSQL text search configuration: no stemming, no stop words, any language. */
    public const string DEFAULT_POSTGRES_CONFIG = 'simple';

    /** The index is missing. */
    public const string MISSING = 'missing';

    /** The index exists and is usable. */
    public const string READY = 'ready';

    /** PostgreSQL only: a `CONCURRENTLY` build failed and left an invalid index (drop it and build again). */
    public const string INVALID = 'invalid';

    /** The platform has no full-text index (the search uses `LIKE`). */
    public const string UNSUPPORTED = 'unsupported';

    /**
     * @param EntityManagerInterface $entityManager ChatKit's manager (the private one when configured)
     * @param string $postgresConfig `search.postgresql_config`
     * @param string $tablePrefix `doctrine.table_prefix`
     * @param string $tableNamePrefix `doctrine.table_name_prefix`
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private string $postgresConfig = self::DEFAULT_POSTGRES_CONFIG,
        private string $tablePrefix = '',
        private string $tableNamePrefix = TablePrefixListener::DEFAULT_TABLE_NAME_PREFIX,
    ) {
        self::assertPostgresConfig($this->postgresConfig);
    }

    /**
     * Rejects a PostgreSQL configuration name that is not a plain lower-case identifier (it is inlined in SQL).
     *
     * @param string $config The configuration name
     *
     * @throws InvalidArgumentException When it is not `[a-z_][a-z0-9_]*`
     */
    public static function assertPostgresConfig(string $config): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $config) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid search.postgresql_config "%s": use the name of a text search configuration (lower-case letters, digits and underscores).', $config));
        }
    }

    /**
     * The physical index name.
     *
     * @return string The name
     */
    public function name(): string
    {
        return TablePrefixListener::physicalName(self::MAPPED_NAME, $this->tablePrefix, $this->tableNamePrefix);
    }

    /**
     * The physical message table.
     *
     * @return string The table name
     */
    public function table(): string
    {
        return $this->entityManager->getClassMetadata(ChatMessage::class)->getTableName();
    }

    /**
     * The indexed column (the message text).
     *
     * @return string The column name
     */
    public function column(): string
    {
        return $this->entityManager->getClassMetadata(ChatMessage::class)->getColumnName('body');
    }

    /**
     * Whether the platform has a full-text index for the search.
     *
     * @return bool True on MySQL, MariaDB and PostgreSQL
     */
    public function supported(): bool
    {
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();

        return $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform;
    }

    /**
     * The state of the index in the database.
     *
     * @return string {@see self::MISSING}, {@see self::READY}, {@see self::INVALID} or {@see self::UNSUPPORTED}
     */
    public function state(): string
    {
        $connection = $this->entityManager->getConnection();
        $platform   = $connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            // A text answer: PDO returns a false boolean column as false, which fetchOne() also returns for "no row".
            $state = $connection->fetchOne(
                "SELECT CASE WHEN i.indisvalid THEN 'ready' ELSE 'invalid' END FROM pg_index i JOIN pg_class x ON x.oid = i.indexrelid"
                . ' JOIN pg_class t ON t.oid = i.indrelid WHERE x.relname = :name AND t.relname = :table AND pg_table_is_visible(x.oid)',
                ['name' => $this->name(), 'table' => $this->table()],
            );

            return $state === self::READY ? self::READY : ($state === self::INVALID ? self::INVALID : self::MISSING);
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            $found = $connection->fetchOne(
                'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :name',
                ['name' => $this->name(), 'table' => $this->table()],
            );

            return $found === false ? self::MISSING : self::READY;
        }

        return self::UNSUPPORTED;
    }

    /**
     * The statement that builds the index (empty on a platform without one).
     *
     * @return string The SQL
     */
    public function createSql(): string
    {
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            return sprintf("CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s USING GIN (to_tsvector('%s'::regconfig, %s))", $this->name(), $this->table(), $this->postgresConfig, $this->column());
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf('CREATE FULLTEXT INDEX %s ON %s (%s)', $this->name(), $this->table(), $this->column());
        }

        return '';
    }

    /**
     * Runs one statement outside any transaction (`CREATE INDEX CONCURRENTLY` refuses to run inside one; MySQL commits
     * DDL implicitly anyway).
     *
     * @param string $sql {@see self::createSql()} or {@see self::dropSql()}
     */
    public function execute(string $sql): void
    {
        $this->entityManager->getConnection()->executeStatement($sql);
    }

    /**
     * The statement that drops the index (empty on a platform without one).
     *
     * @return string The SQL
     */
    public function dropSql(): string
    {
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            return sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $this->name());
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf('DROP INDEX %s ON %s', $this->name(), $this->table());
        }

        return '';
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Throwable;

use function preg_match;
use function str_starts_with;
use function strtolower;

/**
 * 1.27: reports rolled-back transactions and savepoints (with their nesting level), released savepoints, commits and
 * failed commits to {@see ChatKitEntityManager}, which decides whether its identity map is stale (adapted from
 * TaskBoardBundle 2.15.0 / AuthKitBundle 1.46.0, audit NJ-2).
 *
 * @internal
 */
final class PrivateEntityManagerConnection extends AbstractConnectionMiddleware
{
    /** `ROLLBACK TO SAVEPOINT x` (most platforms) and `ROLLBACK TRANSACTION x` (SQL Server). */
    private const string SAVEPOINT_ROLLBACK = '/^\s*ROLLBACK\s+(?:TO\s+SAVEPOINT|TRAN(?:SACTION)?)\s+([A-Za-z0-9_]+)\s*;?\s*$/iD';

    /** `RELEASE SAVEPOINT x`. */
    private const string SAVEPOINT_RELEASE = '/^\s*RELEASE\s+SAVEPOINT\s+([A-Za-z0-9_]+)\s*;?\s*$/iD';

    /** Savepoints of DBAL's nested transactions carry their nesting level. */
    private const string NESTED = '/^DOCTRINE_?(\d+)$/iD';

    /**
     * @param Connection $connection The driver connection
     * @param ChatKitEntityManager $entityManager The private manager told about rollbacks and commits
     */
    public function __construct(
        Connection $connection,
        private readonly ChatKitEntityManager $entityManager,
    ) {
        parent::__construct($connection);
    }

    /**
     * Commits; a failed commit counts as a rollback of everything.
     */
    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $exception) {
            $this->entityManager->connectionRolledBack(1);

            throw $exception;
        }
        $this->entityManager->connectionCommitted();
    }

    /**
     * Rolls back the outermost transaction.
     */
    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->entityManager->connectionRolledBack(1);
        }
    }

    /**
     * Runs a statement; savepoint rollbacks and releases are reported.
     *
     * @param string $sql The statement
     *
     * @return int|string The affected rows
     */
    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);
        if (preg_match(self::SAVEPOINT_ROLLBACK, $sql, $match) === 1 && !str_starts_with(strtolower($match[1]), ChatKitSavepoint::PREFIX)) {
            // A savepoint of unknown level (opened by the application itself): treated as undoing everything pending.
            $this->entityManager->connectionRolledBack(preg_match(self::NESTED, $match[1], $level) === 1 ? (int) $level[1] : null);
        } elseif (preg_match(self::SAVEPOINT_RELEASE, $sql, $match) === 1 && preg_match(self::NESTED, $match[1], $level) === 1) {
            $this->entityManager->savepointReleased((int) $level[1]);
        }

        return $result;
    }
}

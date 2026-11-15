<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * 1.30 (Beacon spec 239): a single-row read that locks the row until the transaction ends.
 *
 * - exclusive: `FOR UPDATE`; on PostgreSQL `FOR NO KEY UPDATE`, which still serialises the writers of the row but lets
 *   the foreign-key checks of inserted children (`FOR KEY SHARE`) through (audit QD-I3);
 * - shared: `FOR SHARE` (MySQL 8, PostgreSQL), `LOCK IN SHARE MODE` (MariaDB): readers of the row do not wait for each
 *   other, but wait for (and hold off) an exclusive lock.
 *
 * SQLite has no row locks (a writing transaction locks the whole database): the plain read runs. Other platforms take
 * DBAL's `FOR UPDATE` for both, or the plain read when they have none.
 *
 * @internal
 */
final class ChatKitRowLock
{
    /**
     * @param Connection $connection The connection of the query
     * @param QueryBuilder $query A DBAL query selecting one value
     * @param bool $shared A shared lock instead of an exclusive one
     *
     * @return mixed The first column of the first row; false when there is none
     */
    public static function fetchOne(Connection $connection, QueryBuilder $query, bool $shared = false): mixed
    {
        $platform = $connection->getDatabasePlatform();
        $clause   = match (true) {
            $platform instanceof PostgreSQLPlatform    => $shared ? 'FOR SHARE' : 'FOR NO KEY UPDATE',
            $platform instanceof MariaDBPlatform       => $shared ? 'LOCK IN SHARE MODE' : 'FOR UPDATE',
            $platform instanceof AbstractMySQLPlatform => $shared ? 'FOR SHARE' : 'FOR UPDATE',
            default                                    => null,
        };
        if ($clause !== null) {
            return $connection->fetchOne($query->getSQL() . ' ' . $clause, $query->getParameters(), $query->getParameterTypes());
        }
        $locked = (clone $query)->forUpdate();
        try {
            $locked->getSQL();
        } catch (DbalException) {
            // Not supported by the platform (DBAL 4: NotSupported; DBAL 3: a plain DBAL exception).
            return $query->fetchOne();
        }

        return $locked->fetchOne();
    }
}

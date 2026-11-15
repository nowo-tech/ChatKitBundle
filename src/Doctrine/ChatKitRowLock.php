<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * 1.30 (Beacon spec 239): a single-row read with `FOR UPDATE` where the platform has it. On SQLite, which has no row
 * locks (a writing transaction locks the whole database), the plain read runs.
 *
 * @internal
 */
final class ChatKitRowLock
{
    /**
     * @param QueryBuilder $query A DBAL query selecting one value
     *
     * @return mixed The first column of the first row; false when there is none
     */
    public static function fetchOne(QueryBuilder $query): mixed
    {
        $locked = (clone $query)->forUpdate();
        try {
            $locked->getSQL();
        } catch (NotSupported) {
            return $query->fetchOne();
        }

        return $locked->fetchOne();
    }
}

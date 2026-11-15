<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Nowo\ChatKitBundle\Doctrine\ChatKitRowLock;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 1.30: the single-row lock read is `FOR UPDATE` where the platform has it, a plain read on SQLite.
 */
final class ChatKitRowLockTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<AbstractPlatform>, bool, string}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL' => [MySQL80Platform::class, false, 'SELECT n FROM t WHERE id = ? FOR UPDATE'];
        yield 'MySQL, shared' => [MySQL80Platform::class, true, 'SELECT n FROM t WHERE id = ? FOR SHARE'];
        yield 'MariaDB, shared' => [MariaDBPlatform::class, true, 'SELECT n FROM t WHERE id = ? LOCK IN SHARE MODE'];
        yield 'MariaDB' => [MariaDBPlatform::class, false, 'SELECT n FROM t WHERE id = ? FOR UPDATE'];
        yield 'PostgreSQL (foreign-key checks of children pass)' => [PostgreSQLPlatform::class, false, 'SELECT n FROM t WHERE id = ? FOR NO KEY UPDATE'];
        yield 'PostgreSQL, shared' => [PostgreSQLPlatform::class, true, 'SELECT n FROM t WHERE id = ? FOR SHARE'];
        yield 'SQLite' => [SQLitePlatform::class, false, 'SELECT n FROM t WHERE id = ?'];
        yield 'Oracle (DBAL\'s FOR UPDATE)' => [OraclePlatform::class, false, 'SELECT n FROM t WHERE id = ? FOR UPDATE'];
    }

    /**
     * @param class-string<AbstractPlatform> $platform
     */
    #[DataProvider('platforms')]
    public function testTheReadLocksTheRowWhereThePlatformCan(string $platform, bool $shared, string $sql): void
    {
        if (TestKernel::dbal3()) {
            self::markTestSkipped('Written against the DBAL 4 platform and result classes (the database job covers DBAL 3).');
        }
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new $platform());
        // The statement comes back as the value read.
        $connection->method('executeQuery')->willReturnCallback(static fn (string $query): Result => new Result(new ArrayResult(['sql'], [[$query]]), $connection));
        $connection->method('fetchOne')->willReturnCallback(static fn (string $query): string => $query);

        self::assertSame($sql, ChatKitRowLock::fetchOne($connection, (new QueryBuilder($connection))->select('n')->from('t')->where('id = ?'), $shared));
    }
}

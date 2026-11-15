<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Nowo\ChatKitBundle\Doctrine\ChatKitRowLock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 1.30: the single-row lock read is `FOR UPDATE` where the platform has it, a plain read on SQLite.
 */
final class ChatKitRowLockTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<AbstractPlatform>, string}>
     */
    public static function platforms(): iterable
    {
        yield 'MySQL' => [MySQL80Platform::class, 'SELECT n FROM t WHERE id = ? FOR UPDATE'];
        yield 'PostgreSQL' => [PostgreSQLPlatform::class, 'SELECT n FROM t WHERE id = ? FOR UPDATE'];
        yield 'SQLite' => [SQLitePlatform::class, 'SELECT n FROM t WHERE id = ?'];
    }

    /**
     * @param class-string<AbstractPlatform> $platform
     */
    #[DataProvider('platforms')]
    public function testTheReadLocksTheRowWhereThePlatformCan(string $platform, string $sql): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new $platform());
        // The statement comes back as the value read.
        $connection->method('executeQuery')->willReturnCallback(static fn (string $query): Result => new Result(new ArrayResult(['sql'], [[$query]]), $connection));

        self::assertSame($sql, ChatKitRowLock::fetchOne((new QueryBuilder($connection))->select('n')->from('t')->where('id = ?')));
    }
}

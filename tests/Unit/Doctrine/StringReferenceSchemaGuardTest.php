<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ChatKitBundle\Doctrine\StringReferenceSchemaGuard;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function strrchr;
use function strtolower;
use function substr;

/**
 * 1.27.1: which collations the `string` mode guard accepts (REQ-SEC-006: `utf8mb4_bin` is PAD SPACE on MySQL 8 and on
 * MariaDB, so `'bob '` would equal `'bob'` there) and how long a passed check is kept (audit AOY-M2: one read of
 * `information_schema` per request, cleared at the request boundary, with no query added to the hot path).
 */
final class StringReferenceSchemaGuardTest extends TestCase
{
    private int $checks = 0;

    private string $collation = 'utf8mb4_0900_bin';

    /**
     * @return iterable<string, array{AbstractPlatform, list<string>}>
     */
    public static function platforms(): iterable
    {
        yield 'MariaDB' => [new MariaDBPlatform(), ['utf8mb4_nopad_bin', 'binary']];
        yield 'MySQL 8.0' => [new MySQL80Platform(), ['utf8mb4_0900_bin', 'binary']];
        yield 'MySQL 8.4' => [new MySQL84Platform(), ['utf8mb4_0900_bin', 'binary']];
        yield 'MySQL 5.7' => [new MySQLPlatform(), ['utf8mb4_bin', 'binary']];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), ['utf8mb4_bin', 'binary']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('platforms')]
    public function testTheAcceptedCollationsPerPlatform(AbstractPlatform $platform, array $expected): void
    {
        // PostgreSQL never reaches the check (assertReady() returns before it); the match arm is the 5.7 default.
        self::assertSame($expected, StringReferenceSchemaGuard::exactFor($platform));
        self::assertNotContains('utf8mb4_bin', StringReferenceSchemaGuard::exactFor(new MySQL84Platform()), 'PAD SPACE on MySQL 8');
        self::assertNotContains('utf8mb4_bin', StringReferenceSchemaGuard::exactFor(new MariaDBPlatform()), 'PAD SPACE on MariaDB');
    }

    public function testUtf8mb4BinIsRefusedOnMySql8AndMariaDb(): void
    {
        $this->collation = 'utf8mb4_bin';
        foreach ([new MySQL84Platform(), new MariaDBPlatform()] as $platform) {
            try {
                (new StringReferenceSchemaGuard($this->registry($platform)))->assertReady();
                self::fail('utf8mb4_bin folds trailing spaces on ' . $platform::class);
            } catch (LogicException $exception) {
                self::assertStringContainsString('still fold', $exception->getMessage());
                self::assertStringContainsString('utf8mb4_bin', $exception->getMessage());
            }
        }
        // MySQL 5.7 has no NO PAD collation: there it is the documented choice and still passes.
        (new StringReferenceSchemaGuard($this->registry(new MySQLPlatform())))->assertReady();
        $this->addToAssertionCount(1);
    }

    public function testThePassIsKeptForOneRequestAndCostsNoExtraQuery(): void
    {
        $guard = new StringReferenceSchemaGuard($this->registry(new MySQL84Platform()));
        $guard->assertReady();
        $first = $this->checks;
        self::assertGreaterThan(0, $first, 'the first call reads information_schema');

        $guard->assertReady();
        $guard->assertReady();
        self::assertSame($first, $this->checks, 'the same connection is not read again inside the request');

        // ChatRequestBoundaryListener resets the guard at every request / command / message boundary: the next
        // request checks the schema again, so a database switched between requests cannot pass on the old check.
        $guard->reset();
        $guard->assertReady();
        self::assertSame($first * 2, $this->checks);
    }

    private function registry(AbstractPlatform $platform): ManagerRegistry
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        // Audit AOY-M2: no `SELECT DATABASE()` (or any other scalar query) on the path of every query by user.
        $connection->expects(self::never())->method('fetchOne');
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $parameters): array {
            ++$this->checks;
            /** @var list<string> $columns */
            $columns = $parameters[1];

            return array_map(fn (string $column): array => ['c' => $column, 'n' => $this->collation], $columns);
        });

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getClassMetadata')->willReturnCallback(function (string $class): ClassMetadata {
            $metadata = $this->createMock(ClassMetadata::class);
            $metadata->method('getTableName')->willReturn('t_' . strtolower(substr(strrchr($class, '\\') ?: $class, 1)));
            $metadata->method('getColumnName')->willReturnCallback(static fn (string $field): string => strtolower($field));

            return $metadata;
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(ChatConversation::class)->willReturn($manager);

        return $registry;
    }
}

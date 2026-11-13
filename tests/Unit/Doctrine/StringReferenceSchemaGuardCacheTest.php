<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\StringReferenceSchemaGuard;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use PHPUnit\Framework\TestCase;

use function array_map;
use function strrchr;
use function strtolower;
use function substr;

/**
 * 1.27.1 (audit AOJ2-L2): the guard's passed check is a fact about one connection on one database, not about the
 * process. A runtime database switch (multi-tenant host, reconnect to another schema) or a rolled-back migration
 * re-reads `information_schema` instead of trusting the earlier pass, and a look-alike row found by a read fails
 * closed before the insert that would clash with it inside a flush.
 */
final class StringReferenceSchemaGuardCacheTest extends TestCase
{
    private int $checks = 0;

    private string $database = 'app';

    public function testThePassedCheckIsKeptPerConnectionAndDatabase(): void
    {
        $guard = new StringReferenceSchemaGuard($this->registry());
        $guard->assertReady();
        $first = $this->checks;
        self::assertGreaterThan(0, $first, 'the first call reads information_schema');

        $guard->assertReady();
        $guard->assertReady();
        self::assertSame($first, $this->checks, 'same connection, same database: the pass is kept');

        // The process switches database (or reconnects to another schema): those columns were never checked.
        $this->database = 'tenant_two';
        $guard->assertReady();
        self::assertSame($first * 2, $this->checks, 'another database is checked again');

        $guard->assertReady();
        self::assertSame($first * 2, $this->checks);

        // Back to the first database, which this connection already passed, is still a pass of its own.
        $this->database = 'app';
        $guard->assertReady();
        self::assertSame($first * 3, $this->checks, 'the last pass of this connection is the one kept');

        $guard->reset();
        $guard->assertReady();
        self::assertSame($first * 4, $this->checks, 'reset() forgets everything');
    }

    public function testALookAlikeRowFailsClosedBeforeTheInsertThatWouldClash(): void
    {
        $guard = new StringReferenceSchemaGuard($this->registry());
        $guard->assertReady();
        $passed = $this->checks;

        // The other modes cannot have look-alike user columns: the row is only ignored.
        (new ChatUserReferences(ChatUserReferences::RELATION, $guard))->assertNoLookAlike('nowo_chat_participant.user_id');
        (new ChatUserReferences(ChatUserReferences::ID, $guard))->assertNoLookAlike('nowo_chat_participant.user_id');
        self::assertSame($passed, $this->checks);

        try {
            (new ChatUserReferences(ChatUserReferences::STRING, $guard))->assertNoLookAlike('nowo_chat_participant.user_id');
            self::fail('a look-alike row must fail closed');
        } catch (LogicException $exception) {
            self::assertStringContainsString('still fold', $exception->getMessage());
            self::assertStringContainsString('nowo_chat_participant.user_id', $exception->getMessage());
        }

        $guard->assertReady();
        self::assertGreaterThan($passed, $this->checks, 'the schema is read again after a look-alike row');
    }

    private function registry(): ManagerRegistry
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL84Platform());
        $connection->method('fetchOne')->willReturnCallback(fn (): string => $this->database);
        $connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $parameters): array {
            ++$this->checks;
            /** @var list<string> $columns */
            $columns = $parameters[1];

            return array_map(static fn (string $column): array => ['c' => $column, 'n' => 'utf8mb4_bin'], $columns);
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

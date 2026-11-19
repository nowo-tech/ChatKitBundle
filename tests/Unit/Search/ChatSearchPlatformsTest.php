<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Search;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Command\ChatSearchIndexCommand;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Search\ChatMessageSearchSql;
use Nowo\ChatKitBundle\Search\ChatSearchIndex;
use Nowo\ChatKitBundle\Search\ChatSearchSchemaListener;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * 1.37: the platform-specific SQL of message search (full-text conditions, index statements and state, the schema
 * listener) and the index command's answers, without a server. The real servers run tests/Database.
 */
final class ChatSearchPlatformsTest extends TestCase
{
    public function testTextMatchPerPlatform(): void
    {
        $sql   = new ChatMessageSearchSql($this->createMock(EntityManagerInterface::class));
        $words = ['invoice', 'ok', 'c-12', '50%_off!'];

        self::assertSame([
            ['MATCH (m.body) AGAINST (:terms IN BOOLEAN MODE)', "LOWER(m.body) LIKE :like0 ESCAPE '!'", "LOWER(m.body) LIKE :like1 ESCAPE '!'", "LOWER(m.body) LIKE :like2 ESCAPE '!'"],
            ['terms' => '+invoice*', 'like0' => '%ok%', 'like1' => '%c-12%', 'like2' => '%50!%!_off!!%'],
        ], $sql->textMatch(new MySQL80Platform(), 'm.body', $words));

        self::assertSame([
            ["to_tsvector('simple'::regconfig, m.body) @@ to_tsquery('simple'::regconfig, :terms)", "LOWER(m.body) LIKE :like0 ESCAPE '!'", "LOWER(m.body) LIKE :like1 ESCAPE '!'"],
            ['terms' => 'invoice:* & ok:*', 'like0' => '%c-12%', 'like1' => '%50!%!_off!!%'],
        ], $sql->textMatch(new PostgreSQLPlatform(), 'm.body', $words));

        [$conditions] = (new ChatMessageSearchSql($this->createMock(EntityManagerInterface::class), true, 'english'))->textMatch(new PostgreSQLPlatform(), 'b', ['x']) ?? [[], []];
        self::assertSame(["to_tsvector('english'::regconfig, b) @@ to_tsquery('english'::regconfig, :terms)"], $conditions);

        // Other platforms and `fulltext: false`: LIKE only.
        foreach ([[new OraclePlatform(), true], [new MySQL80Platform(), false], [new PostgreSQLPlatform(), false]] as [$platform, $fulltext]) {
            [$conditions, $parameters] = (new ChatMessageSearchSql($this->createMock(EntityManagerInterface::class), $fulltext))->textMatch($platform, 'b', ['invoice']) ?? [[], []];
            self::assertSame(["LOWER(b) LIKE :like0 ESCAPE '!'"], $conditions);
            self::assertSame(['like0' => '%invoice%'], $parameters);
        }
        // Audit VA-7: on a full-text platform a query with no indexable word is refused (no table scan).
        self::assertNull($sql->textMatch(new MySQL80Platform(), 'b', ['ok', 'c-12']));
        self::assertNull($sql->textMatch(new PostgreSQLPlatform(), 'b', ['c-12']));
        self::assertNotNull($sql->textMatch(new OraclePlatform(), 'b', ['c-12']));
        // A lower MySQL token size sends short words to the index.
        self::assertSame('+ok*', (new ChatMessageSearchSql($this->createMock(EntityManagerInterface::class), true, 'simple', 2))->textMatch(new MySQL80Platform(), 'b', ['ok'])[1]['terms'] ?? null);
    }

    public function testInvalidPostgresConfig(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid search.postgresql_config "simple\'; drop"');
        new ChatSearchIndex($this->createMock(EntityManagerInterface::class), "simple'; drop");
    }

    public function testIndexPerPlatform(): void
    {
        $mysql = new ChatSearchIndex($this->entityManager(new MySQL80Platform())[0], 'simple', 'app_', 'chat_');
        self::assertSame('app_idx_chat_message_body_fts', $mysql->name());
        self::assertSame('nowo_chat_message', $mysql->table());
        self::assertSame('body', $mysql->column());
        self::assertTrue($mysql->supported());
        self::assertSame('CREATE FULLTEXT INDEX app_idx_chat_message_body_fts ON nowo_chat_message (body)', $mysql->createSql());
        self::assertSame('DROP INDEX app_idx_chat_message_body_fts ON nowo_chat_message', $mysql->dropSql());

        $postgres = new ChatSearchIndex($this->entityManager(new PostgreSQLPlatform())[0]);
        self::assertSame("CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_nowo_chat_message_body_fts ON nowo_chat_message USING GIN (to_tsvector('simple'::regconfig, body))", $postgres->createSql());
        self::assertSame('DROP INDEX CONCURRENTLY IF EXISTS idx_nowo_chat_message_body_fts', $postgres->dropSql());

        $other = new ChatSearchIndex($this->entityManager(new OraclePlatform())[0]);
        self::assertFalse($other->supported());
        self::assertSame(ChatSearchIndex::UNSUPPORTED, $other->state());
        self::assertSame('', $other->createSql());
        self::assertSame('', $other->dropSql());
    }

    public function testIndexState(): void
    {
        foreach ([[new MySQL80Platform(), 1, ChatSearchIndex::READY], [new MySQL80Platform(), false, ChatSearchIndex::MISSING], [new PostgreSQLPlatform(), 'ready', ChatSearchIndex::READY], [new PostgreSQLPlatform(), 'invalid', ChatSearchIndex::INVALID], [new PostgreSQLPlatform(), false, ChatSearchIndex::MISSING]] as [$platform, $answer, $state]) {
            [$entityManager, $connection] = $this->entityManager($platform);
            $connection->method('fetchOne')->willReturn($answer);
            self::assertSame($state, (new ChatSearchIndex($entityManager))->state());
        }
    }

    public function testCommand(): void
    {
        // A platform without an index.
        $tester = new CommandTester(new ChatSearchIndexCommand(new ChatSearchIndex($this->entityManager(new OraclePlatform())[0])));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Nothing to do.', $tester->getDisplay());

        // Printing never runs anything.
        [$entityManager, $connection] = $this->entityManager(new MySQL80Platform());
        $connection->method('fetchOne')->willReturn(false);
        $connection->expects(self::never())->method('executeStatement');
        $tester = new CommandTester(new ChatSearchIndexCommand(new ChatSearchIndex($entityManager)));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('missing', $tester->getDisplay());
        self::assertStringContainsString('CREATE FULLTEXT INDEX idx_nowo_chat_message_body_fts ON nowo_chat_message (body);', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--drop' => true]));
        self::assertStringContainsString('DROP INDEX idx_nowo_chat_message_body_fts ON nowo_chat_message;', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--drop' => true, '--force' => true]));
        self::assertStringContainsString('Nothing to drop.', $tester->getDisplay());

        // Built, dropped.
        [$entityManager, $connection] = $this->entityManager(new MySQL80Platform());
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(false, 1, 1);
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturn(0);
        $tester = new CommandTester(new ChatSearchIndexCommand(new ChatSearchIndex($entityManager)));
        self::assertSame(0, $tester->execute(['--force' => true]));
        self::assertStringContainsString('Index built.', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--force' => true]));
        self::assertStringContainsString('already there', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--force' => true, '--drop' => true]));
        self::assertStringContainsString('Index dropped.', $tester->getDisplay());

        // PostgreSQL: an invalid index is dropped before the build.
        [$entityManager, $connection] = $this->entityManager(new PostgreSQLPlatform());
        $connection->method('fetchOne')->willReturn('invalid');
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 0;
        });
        self::assertSame(0, (new CommandTester(new ChatSearchIndexCommand(new ChatSearchIndex($entityManager))))->execute(['--force' => true]));
        self::assertStringStartsWith('DROP INDEX CONCURRENTLY', $statements[0]);
        self::assertStringStartsWith('CREATE INDEX CONCURRENTLY', $statements[1]);
    }

    public function testCommandConflictAndOtherErrors(): void
    {
        [$entityManager, $connection] = $this->entityManager(new MySQL80Platform());
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('executeStatement')->willThrowException(new DeadlockException($this->driverException(1213, '40001'), null));
        $tester = new CommandTester(new ChatSearchIndexCommand(new ChatSearchIndex($entityManager)));
        self::assertSame(ChatSearchIndexCommand::CONFLICT, $tester->execute(['--force' => true]));
        self::assertStringContainsString('CONFLICT_001', $tester->getDisplay());

        [$entityManager, $connection] = $this->entityManager(new MySQL80Platform());
        $connection->method('fetchOne')->willReturn(false);
        $connection->method('executeStatement')->willThrowException(new SyntaxErrorException($this->driverException(1064, '42000'), null));
        $this->expectException(SyntaxErrorException::class);
        (new CommandTester(new ChatSearchIndexCommand(new ChatSearchIndex($entityManager))))->execute(['--force' => true]);
    }

    public function testSchemaListener(): void
    {
        $listener = new ChatSearchSchemaListener('', 'nowo_chat_', true, true);

        // MySQL: the index is added once.
        [$entityManager] = $this->entityManager(new MySQL80Platform());
        $schema          = new Schema();
        $table           = $schema->createTable('nowo_chat_message');
        $table->addColumn('body', 'text');
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($entityManager, $schema));
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($entityManager, $schema));
        self::assertTrue($table->hasIndex('idx_nowo_chat_message_body_fts'));
        self::assertTrue($table->getIndex('idx_nowo_chat_message_body_fts')->hasFlag('fulltext'));

        // No message table in this schema, another platform, or a manager without the chat entities: nothing.
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($entityManager, new Schema()));
        foreach ([$this->entityManager(new PostgreSQLPlatform())[0], $this->entityManager(new MySQL80Platform(), true)[0]] as $other) {
            $schema = new Schema();
            $schema->createTable('nowo_chat_message')->addColumn('body', 'text');
            $listener->postGenerateSchema(new GenerateSchemaEventArgs($other, $schema));
            self::assertSame([], $schema->getTable('nowo_chat_message')->getIndexes());
        }

        // Audit VA-4: with search (or full text) off, the index is mapped only while it exists in the database, so a diff
        // never drops it and a host that never used search never gets one.
        foreach ([[false, true, 1, true], [false, true, false, false], [true, false, 1, true], [true, false, false, false]] as [$enabled, $fulltext, $found, $mapped]) {
            [$entityManager, $connection] = $this->entityManager(new MySQL80Platform());
            $connection->method('fetchOne')->willReturn($found);
            $schema = new Schema();
            $schema->createTable('nowo_chat_message')->addColumn('body', 'text');
            (new ChatSearchSchemaListener('', 'nowo_chat_', $enabled, $fulltext))->postGenerateSchema(new GenerateSchemaEventArgs($entityManager, $schema));
            self::assertSame($mapped, $schema->getTable('nowo_chat_message')->hasIndex('idx_nowo_chat_message_body_fts'));
        }
    }

    /**
     * @return array{0: EntityManagerInterface&MockObject, 1: Connection&MockObject}
     */
    private function entityManager(AbstractPlatform $platform, bool $transient = false): array
    {
        $metadata = new ClassMetadata(ChatMessage::class);
        $metadata->setPrimaryTable(['name' => 'nowo_chat_message']);
        $metadata->mapField(['fieldName' => 'body', 'type' => 'text']);
        $factory = $this->createMock(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn($transient);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturn($metadata);
        $entityManager->method('getMetadataFactory')->willReturn($factory);

        return [$entityManager, $connection];
    }

    private function driverException(int $code, string $sqlState): DriverException
    {
        return new class('driver failure', $code, $sqlState) extends RuntimeException implements DriverException {
            public function __construct(string $message, int $code, private readonly string $sqlState)
            {
                parent::__construct($message, $code);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
    }
}

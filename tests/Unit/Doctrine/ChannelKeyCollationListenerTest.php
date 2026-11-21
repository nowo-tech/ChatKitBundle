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
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Nowo\ChatKitBundle\Doctrine\ChannelKeyCollationListener;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function class_exists;
use function method_exists;

/**
 * House rule LQ: `channel_key` gets a binary NO PAD collation on MySQL 8 / MariaDB, so look-alike keys are distinct rows.
 * 1.40.1: applied on `postGenerateSchema`, by table name (the table event is a deprecated no-op).
 */
final class ChannelKeyCollationListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform, string|null}>
     */
    public static function platforms(): iterable
    {
        // DBAL 3 (the database job's second DBAL) has no MySQL84Platform, names SQLite `SqlitePlatform` and, in older 3.x
        // releases, has no MariaDBPlatform; PHPUnit builds every data provider before it applies --filter.
        if (class_exists(MariaDBPlatform::class)) {
            yield 'MariaDB' => [new MariaDBPlatform(), 'utf8mb4_nopad_bin'];
        }
        yield 'MySQL 8.0' => [new MySQL80Platform(), 'utf8mb4_0900_bin'];
        if (class_exists(MySQL84Platform::class)) {
            yield 'MySQL 8.4' => [new MySQL84Platform(), 'utf8mb4_0900_bin'];
        }
        yield 'MySQL 5.7' => [new MySQLPlatform(), 'utf8mb4_bin'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), null];
        if (class_exists(SQLitePlatform::class)) {
            yield 'SQLite' => [new SQLitePlatform(), null];
        }
    }

    #[DataProvider('platforms')]
    public function testCollationPerPlatform(AbstractPlatform $platform, ?string $expected): void
    {
        self::assertSame($expected, ChannelKeyCollationListener::collationFor($platform));

        $args   = $this->args($platform);
        $schema = $args->getSchema();
        (new ChannelKeyCollationListener())->postGenerateSchema($args);
        $column = $args->getSchema()->getTable('nowo_chat_conversation')->getColumn('channel_key');
        self::assertSame($expected, $column->getPlatformOptions()['collation'] ?? null);
        self::assertSame($expected === null ? null : 'utf8mb4', $column->getPlatformOptions()['charset'] ?? null);
        if ($expected === null) {
            self::assertSame($schema, $args->getSchema(), 'nothing to collate: the schema is kept');
        }
        self::assertArrayNotHasKey('collation', $args->getSchema()->getTable('nowo_chat_message')->getColumn('channel_key')->getPlatformOptions(), 'other tables are untouched');
    }

    public function testAManagerWithoutTheBundleEntitiesOrWithoutTheTableIsUntouched(): void
    {
        $platform = new MySQL80Platform();
        $args     = $this->args($platform, transient: true);
        $schema   = $args->getSchema();
        (new ChannelKeyCollationListener())->postGenerateSchema($args);
        self::assertSame($schema, $args->getSchema());

        $args   = $this->args($platform, tables: false);
        $schema = $args->getSchema();
        (new ChannelKeyCollationListener())->postGenerateSchema($args);
        self::assertSame($schema, $args->getSchema(), 'table filtered out of the schema');
    }

    public function testStringModeCollatesTheUserReferenceColumnsOfEveryTable(): void
    {
        $args = $this->args(new MySQL80Platform(), stringMode: true);
        (new ChannelKeyCollationListener(null, new ChatUserReferences(ChatUserReferences::STRING)))->postGenerateSchema($args);
        self::assertSame('utf8mb4_0900_bin', $args->getSchema()->getTable('nowo_chat_conversation')->getColumn('channel_key')->getPlatformOptions()['collation'] ?? null);
        self::assertSame('utf8mb4_0900_bin', $args->getSchema()->getTable('nowo_chat_conversation')->getColumn('support_owner_id')->getPlatformOptions()['collation'] ?? null);
        self::assertSame('utf8mb4_0900_bin', $args->getSchema()->getTable('nowo_chat_message')->getColumn('author_id')->getPlatformOptions()['collation'] ?? null);
        self::assertArrayNotHasKey('collation', $args->getSchema()->getTable('nowo_chat_message')->getColumn('channel_key')->getPlatformOptions());
    }

    public function testCollateInPlaceAndThroughTheEditor(): void
    {
        $table = $this->table('nowo_chat_conversation');
        self::assertNull(ChannelKeyCollationListener::collate($table, 'channel_key', 'utf8mb4_0900_bin', false));
        self::assertSame('utf8mb4_0900_bin', $table->getColumn('channel_key')->getPlatformOptions()['collation'] ?? null);

        // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
        if (!method_exists(Table::class, 'edit')) {
            return;
        }
        $edited = ChannelKeyCollationListener::collate($this->table('nowo_chat_conversation'), 'channel_key', 'utf8mb4_nopad_bin', true);
        self::assertInstanceOf(Table::class, $edited);
        self::assertSame('utf8mb4_nopad_bin', $edited->getColumn('channel_key')->getPlatformOptions()['collation'] ?? null);
    }

    private function table(string $name): Table
    {
        return new Table($name, [
            new Column('channel_key', Type::getType(Types::STRING), ['length' => 120, 'notnull' => false]),
            new Column('support_owner_id', Type::getType(Types::STRING), ['length' => 64, 'notnull' => false]),
            new Column('author_id', Type::getType(Types::STRING), ['length' => 64, 'notnull' => false]),
        ]);
    }

    /**
     * The schema tool's event with the conversation and message tables (each with all three columns).
     */
    private function args(AbstractPlatform $platform, bool $transient = false, bool $tables = true, bool $stringMode = false): GenerateSchemaEventArgs
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn($transient);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getMetadataFactory')->willReturn($factory);
        $manager->method('getClassMetadata')->willReturnCallback(static function (string $class) use ($stringMode): ClassMetadata {
            /** @var class-string $class */
            $metadata = new ClassMetadata($class);
            $metadata->setPrimaryTable(['name' => $class === ChatConversation::class ? 'nowo_chat_conversation' : ($class === ChatMessage::class ? 'nowo_chat_message' : 'nowo_chat_other')]);
            if ($class === ChatConversation::class) {
                $metadata->mapField(['fieldName' => 'channelKey', 'columnName' => 'channel_key', 'type' => Types::STRING, 'length' => 120, 'nullable' => true]);
            }
            if ($stringMode && $class === ChatConversation::class) {
                $metadata->mapField(['fieldName' => 'supportOwnerRef', 'columnName' => 'support_owner_id', 'type' => Types::STRING, 'length' => 64, 'nullable' => true]);
            }
            if ($stringMode && $class === ChatMessage::class) {
                $metadata->mapField(['fieldName' => 'authorRef', 'columnName' => 'author_id', 'type' => Types::STRING, 'length' => 64, 'nullable' => true]);
            }

            return $metadata;
        });

        return new GenerateSchemaEventArgs($manager, new Schema($tables ? [$this->table('nowo_chat_conversation'), $this->table('nowo_chat_message')] : []));
    }
}

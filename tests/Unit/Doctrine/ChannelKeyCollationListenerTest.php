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
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\ChatKitBundle\Doctrine\ChannelKeyCollationListener;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function class_exists;
use function method_exists;

/**
 * House rule LQ: `channel_key` gets a binary NO PAD collation on MySQL 8 / MariaDB, so look-alike keys are distinct rows.
 */
final class ChannelKeyCollationListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{AbstractPlatform, string|null}>
     */
    public static function platforms(): iterable
    {
        // DBAL 3 (the race job's second DBAL) has neither MySQL84Platform nor, in older 3.x releases, MariaDBPlatform.
        if (class_exists(MariaDBPlatform::class)) {
            yield 'MariaDB' => [new MariaDBPlatform(), 'utf8mb4_nopad_bin'];
        }
        yield 'MySQL 8.0' => [new MySQL80Platform(), 'utf8mb4_0900_bin'];
        if (class_exists(MySQL84Platform::class)) {
            yield 'MySQL 8.4' => [new MySQL84Platform(), 'utf8mb4_0900_bin'];
        }
        yield 'MySQL 5.7' => [new MySQLPlatform(), 'utf8mb4_bin'];
        yield 'PostgreSQL' => [new PostgreSQLPlatform(), null];
        yield 'SQLite' => [new SQLitePlatform(), null];
    }

    #[DataProvider('platforms')]
    public function testCollationPerPlatform(AbstractPlatform $platform, ?string $expected): void
    {
        self::assertSame($expected, ChannelKeyCollationListener::collationFor($platform));

        $args = $this->args(ChatConversation::class, $platform);
        (new ChannelKeyCollationListener($this->registry($platform)))->postGenerateSchemaTable($args);
        $column = $args->getClassTable()->getColumn('channel_key');
        self::assertSame($expected, $column->getPlatformOptions()['collation'] ?? null);
        self::assertSame($expected === null ? null : 'utf8mb4', $column->getPlatformOptions()['charset'] ?? null);
    }

    public function testOtherEntitiesAndUnknownManagersAreUntouched(): void
    {
        $platform = new MySQL80Platform();
        $args     = $this->args(ChatMessage::class, $platform);
        (new ChannelKeyCollationListener($this->registry($platform)))->postGenerateSchemaTable($args);
        self::assertArrayNotHasKey('collation', $args->getClassTable()->getColumn('channel_key')->getPlatformOptions());

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->createStub(ObjectManager::class));
        $args = $this->args(ChatConversation::class, $platform);
        (new ChannelKeyCollationListener($registry))->postGenerateSchemaTable($args);
        self::assertArrayNotHasKey('collation', $args->getClassTable()->getColumn('channel_key')->getPlatformOptions());
    }

    public function testCollateInPlaceAndThroughTheEditor(): void
    {
        $table = $this->table();
        self::assertNull(ChannelKeyCollationListener::collate($table, 'channel_key', 'utf8mb4_0900_bin', false));
        self::assertSame('utf8mb4_0900_bin', $table->getColumn('channel_key')->getPlatformOptions()['collation'] ?? null);

        // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
        if (!method_exists(Table::class, 'edit')) {
            return;
        }
        $edited = ChannelKeyCollationListener::collate($this->table(), 'channel_key', 'utf8mb4_nopad_bin', true);
        self::assertInstanceOf(Table::class, $edited);
        self::assertSame('utf8mb4_nopad_bin', $edited->getColumn('channel_key')->getPlatformOptions()['collation'] ?? null);
    }

    private function table(): Table
    {
        return new Table('nowo_chat_conversation', [new Column('channel_key', Type::getType(Types::STRING), ['length' => 120, 'notnull' => false])]);
    }

    /**
     * @param class-string $class
     */
    private function args(string $class, AbstractPlatform $platform): GenerateSchemaTableEventArgs
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => 'nowo_chat_conversation']);
        $metadata->mapField(['fieldName' => 'channelKey', 'columnName' => 'channel_key', 'type' => Types::STRING, 'length' => 120, 'nullable' => true]);

        return new GenerateSchemaTableEventArgs($metadata, new Schema(), $this->table());
    }

    private function registry(AbstractPlatform $platform): ManagerRegistry
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return $registry;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefChatUser;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefDirectory;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_map;
use function assert;
use function in_array;
use function ltrim;
use function parse_url;
use function preg_match;
use function sort;
use function sprintf;
use function str_replace;
use function strtolower;
use function strtoupper;

use const PHP_URL_PATH;

/**
 * 1.40.1 regression (FK loss), on the server of CHAT_KIT_RACE_DATABASE_URL (MySQL 8.4, MariaDB 11.4 and PostgreSQL 16
 * in CI, job "Load readiness"): in every `doctrine.user_reference` mode the schema tool, with ChannelKeyCollationListener
 * registered, creates every foreign key the mapping declares, and the server enforces them: a deleted conversation
 * takes its messages and participants with it (ON DELETE CASCADE) and, in `relation` mode, a deleted support owner
 * takes its support conversation (ON DELETE CASCADE) and a deleted resolver is unset (ON DELETE SET NULL). 1.26.4 to
 * 1.40.0 created none of the foreign keys starting on a collated table on MySQL / MariaDB with doctrine/orm 3.7 / 3.8
 * and doctrine/dbal 4.5+.
 *
 * Every test drops and creates the schema in its own mode; the next LoadDatabaseTestCase test creates its default
 * schema again.
 */
final class SchemaForeignKeysDatabaseTest extends LoadDatabaseTestCase
{
    /**
     * The UPGRADING.md "To 1.40.1" recovery for `relation` mode (MySQL / MariaDB; `app_user` stands for the
     * application's user table): orphans removed or unset, then the indexes and the foreign keys.
     */
    public const RECOVERY_RELATION = [
        'DELETE c FROM nowo_chat_conversation c LEFT JOIN app_user u ON u.id = c.support_owner_id WHERE c.support_owner_id IS NOT NULL AND u.id IS NULL',
        'UPDATE nowo_chat_conversation c LEFT JOIN app_user u ON u.id = c.resolved_by_id SET c.resolved_by_id = NULL WHERE c.resolved_by_id IS NOT NULL AND u.id IS NULL',
        'UPDATE nowo_chat_conversation c LEFT JOIN app_user u ON u.id = c.assignee_id SET c.assignee_id = NULL WHERE c.assignee_id IS NOT NULL AND u.id IS NULL',
        'CREATE INDEX IDX_D7385CFFCA8AEAC6 ON nowo_chat_conversation (support_owner_id)',
        'CREATE INDEX IDX_D7385CFF6713A32B ON nowo_chat_conversation (resolved_by_id)',
        'ALTER TABLE nowo_chat_conversation ADD CONSTRAINT FK_D7385CFFCA8AEAC6 FOREIGN KEY (support_owner_id) REFERENCES app_user (id) ON DELETE CASCADE',
        'ALTER TABLE nowo_chat_conversation ADD CONSTRAINT FK_D7385CFF6713A32B FOREIGN KEY (resolved_by_id) REFERENCES app_user (id) ON DELETE SET NULL',
        'ALTER TABLE nowo_chat_conversation ADD CONSTRAINT FK_D7385CFF59EC7D60 FOREIGN KEY (assignee_id) REFERENCES app_user (id) ON DELETE SET NULL',
    ];

    /** The UPGRADING.md "To 1.40.1" recovery for `string` mode (MySQL / MariaDB). */
    public const RECOVERY_STRING = [
        'DELETE x FROM nowo_chat_participant x LEFT JOIN nowo_chat_conversation c ON c.id = x.conversation_id WHERE c.id IS NULL',
        'DELETE x FROM nowo_chat_conversation_link x LEFT JOIN nowo_chat_conversation c ON c.id = x.conversation_id WHERE c.id IS NULL',
        'DELETE x FROM nowo_chat_message x LEFT JOIN nowo_chat_conversation c ON c.id = x.conversation_id WHERE c.id IS NULL',
        'DELETE x FROM nowo_chat_message x LEFT JOIN nowo_chat_message r ON r.id = x.thread_root_id WHERE x.thread_root_id IS NOT NULL AND r.id IS NULL',
        'UPDATE nowo_chat_message x LEFT JOIN nowo_chat_message f ON f.id = x.forwarded_from_message_id SET x.forwarded_from_message_id = NULL WHERE x.forwarded_from_message_id IS NOT NULL AND f.id IS NULL',
        'UPDATE nowo_chat_message x LEFT JOIN nowo_chat_conversation c ON c.id = x.forwarded_from_conversation_id SET x.forwarded_from_conversation_id = NULL WHERE x.forwarded_from_conversation_id IS NOT NULL AND c.id IS NULL',
        'DELETE x FROM nowo_chat_message_mention x LEFT JOIN nowo_chat_message m ON m.id = x.message_id WHERE m.id IS NULL',
        'DELETE x FROM nowo_chat_reaction x LEFT JOIN nowo_chat_message m ON m.id = x.message_id WHERE m.id IS NULL',
        'DELETE x FROM nowo_chat_saved_message x LEFT JOIN nowo_chat_message m ON m.id = x.message_id WHERE m.id IS NULL',
        'DELETE x FROM nowo_chat_thread_read x LEFT JOIN nowo_chat_message m ON m.id = x.root_message_id WHERE m.id IS NULL',
        'CREATE INDEX IDX_A40D82CC9AC0396 ON nowo_chat_message (conversation_id)',
        'CREATE INDEX IDX_A40D82CCC8BC80CF ON nowo_chat_message (thread_root_id)',
        'CREATE INDEX IDX_A40D82CC4645CC3E ON nowo_chat_message (forwarded_from_message_id)',
        'CREATE INDEX IDX_A40D82CCBDD9A572 ON nowo_chat_message (forwarded_from_conversation_id)',
        'CREATE INDEX IDX_9DF33B9B537A1329 ON nowo_chat_message_mention (message_id)',
        'CREATE INDEX IDX_9FE7FEB9AC0396 ON nowo_chat_participant (conversation_id)',
        'CREATE INDEX IDX_F6AD5573537A1329 ON nowo_chat_reaction (message_id)',
        'CREATE INDEX IDX_C835FCC3537A1329 ON nowo_chat_saved_message (message_id)',
        'CREATE INDEX IDX_C2874913F639B458 ON nowo_chat_thread_read (root_message_id)',
        'ALTER TABLE nowo_chat_message ADD CONSTRAINT FK_A40D82CC9AC0396 FOREIGN KEY (conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE CASCADE',
        'ALTER TABLE nowo_chat_message ADD CONSTRAINT FK_A40D82CCC8BC80CF FOREIGN KEY (thread_root_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE',
        'ALTER TABLE nowo_chat_message ADD CONSTRAINT FK_A40D82CC4645CC3E FOREIGN KEY (forwarded_from_message_id) REFERENCES nowo_chat_message (id) ON DELETE SET NULL',
        'ALTER TABLE nowo_chat_message ADD CONSTRAINT FK_A40D82CCBDD9A572 FOREIGN KEY (forwarded_from_conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE SET NULL',
        'ALTER TABLE nowo_chat_message_mention ADD CONSTRAINT FK_9DF33B9B537A1329 FOREIGN KEY (message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE',
        'ALTER TABLE nowo_chat_participant ADD CONSTRAINT FK_9FE7FEB9AC0396 FOREIGN KEY (conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE CASCADE',
        'ALTER TABLE nowo_chat_reaction ADD CONSTRAINT FK_F6AD5573537A1329 FOREIGN KEY (message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE',
        'ALTER TABLE nowo_chat_saved_message ADD CONSTRAINT FK_C835FCC3537A1329 FOREIGN KEY (message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE',
        'ALTER TABLE nowo_chat_thread_read ADD CONSTRAINT FK_C2874913F639B458 FOREIGN KEY (root_message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE',
        'ALTER TABLE nowo_chat_conversation_link ADD CONSTRAINT FK_C437798C9AC0396 FOREIGN KEY (conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE CASCADE',
    ];

    private RefDirectory $directory;

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield ChatUserReferences::RELATION => [ChatUserReferences::RELATION];
        yield ChatUserReferences::ID => [ChatUserReferences::ID];
        yield ChatUserReferences::STRING => [ChatUserReferences::STRING];
    }

    // Not the parent's setUp(): each test creates the schema of its own mode (see the class comment).
    // @phpstan-ignore phpunit.callParent
    protected function setUp(): void
    {
        $url = TestKernel::databaseUrl();
        if ($url === null) {
            self::markTestSkipped('Set CHAT_KIT_RACE_DATABASE_URL to a throwaway server database (MySQL or PostgreSQL).');
        }
        $database = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (preg_match('/(?:^|_)(?:test|race|throwaway)(?:_|$)/', $database) !== 1) {
            throw new RuntimeException(sprintf('Refusing to use database "%s": the setup drops everything in it.', $database));
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // The schema of this test's mode is left behind: the next load test creates its own again.
        self::$schema = false; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    #[DataProvider('modes')]
    public function testEveryForeignKeyIsCreatedAndHolds(string $mode): void
    {
        $this->boot($mode);
        $tool = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $expected = $this->mappedForeignKeys();
        self::assertNotSame([], $expected);
        self::assertSame($expected, $this->serverForeignKeys());
        if ($mode === ChatUserReferences::STRING) {
            self::assertContains('nowo_chat_message.conversation_id -> nowo_chat_conversation(id) CASCADE', $expected);
            self::assertContains('nowo_chat_reaction.message_id -> nowo_chat_message(id) CASCADE', $expected);
        }
        if ($mode === ChatUserReferences::RELATION) {
            self::assertContains('nowo_chat_conversation.support_owner_id -> test_user(id) CASCADE', $expected);
        }

        [$ana, $bob] = $this->people($mode);
        $chat        = $this->testService(ChatServiceInterface::class);
        $direct      = $chat->openDirect($ana, $bob);
        $message     = $chat->send($ana, $direct, 'hello');
        $chat->toggleReaction($bob, $message, '👍');
        $id         = (int) $direct->getId();
        $connection = $this->connection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_chat_reaction'));

        if ($mode === ChatUserReferences::RELATION) {
            $connection->executeStatement('UPDATE nowo_chat_conversation SET resolved_by_id = ? WHERE id = ?', [$this->userId('ana'), $id]);
            $connection->executeStatement('DELETE FROM test_user WHERE id = ?', [$this->userId('ana')]);
            self::assertNull($connection->fetchOne('SELECT resolved_by_id FROM nowo_chat_conversation WHERE id = ?', [$id]), 'resolver ON DELETE SET NULL');

            $connection->executeStatement('UPDATE nowo_chat_conversation SET support_owner_id = ? WHERE id = ?', [$this->userId('bob'), $id]);
            $connection->executeStatement('DELETE FROM test_user WHERE id = ?', [$this->userId('bob')]);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation WHERE id = ?', [$id]), 'support owner ON DELETE CASCADE');
        } else {
            $connection->executeStatement('DELETE FROM nowo_chat_conversation WHERE id = ?', [$id]);
        }
        foreach (['nowo_chat_message', 'nowo_chat_participant', 'nowo_chat_reaction'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table), $table . ': ON DELETE CASCADE');
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function affectedModes(): iterable
    {
        yield ChatUserReferences::RELATION => [ChatUserReferences::RELATION];
        yield ChatUserReferences::STRING => [ChatUserReferences::STRING];
    }

    /**
     * A MySQL / MariaDB database created by 1.26.4 to 1.40.0 (the foreign keys starting on the collated tables and
     * their indexes missing) is recovered by the UPGRADING.md statements: the keys are back and the diff is empty.
     */
    #[DataProvider('affectedModes')]
    public function testTheUpgradingRecoveryRestoresTheLostKeys(string $mode): void
    {
        $this->boot($mode);
        $tool = new SchemaTool($this->em);
        $tool->dropDatabase();
        $tool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $connection = $this->connection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertSame([], $tool->getUpdateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()), 'PostgreSQL was never affected');

            return;
        }
        $expected = $this->serverForeignKeys();
        $recovery = $mode === ChatUserReferences::RELATION ? self::RECOVERY_RELATION : self::RECOVERY_STRING;
        foreach ($recovery as $statement) {
            if (preg_match('/^ALTER TABLE (\w+) ADD CONSTRAINT (\w+) /', $statement, $key) === 1) {
                $connection->executeStatement(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $key[1], $key[2]));
            }
        }
        foreach ($recovery as $statement) {
            if (preg_match('/^CREATE INDEX (\w+) ON (\w+) /', $statement, $index) === 1) {
                $connection->executeStatement(sprintf('DROP INDEX %s ON %s', $index[1], $index[2]));
            }
        }
        self::assertNotSame($expected, $this->serverForeignKeys());

        foreach ($recovery as $statement) {
            $connection->executeStatement(str_replace('app_user', 'test_user', $statement));
        }

        self::assertSame($expected, $this->serverForeignKeys());
        self::assertSame([], $tool->getUpdateSchemaSql($this->em->getMetadataFactory()->getAllMetadata()));
    }

    private function boot(string $mode): void
    {
        $this->kernel = new TestKernel(['profiles' => ['default' => ($mode === ChatUserReferences::STRING ? ['user_class' => null] : []) + ['doctrine' => ['user_reference' => $mode]]]]);
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    private function testServices(): ContainerInterface
    {
        $services = $this->kernel->getContainer()->get('test.service_container');
        assert($services instanceof ContainerInterface);

        return $services;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function testService(string $id): object
    {
        $service = $this->testServices()->get($id);
        assert($service instanceof $id);

        return $service;
    }

    /**
     * @return array{ChatUserInterface, ChatUserInterface}
     */
    private function people(string $mode): array
    {
        if ($mode !== ChatUserReferences::STRING) {
            return [$this->user('ana'), $this->user('bob')];
        }
        $this->directory = new RefDirectory();
        $this->testServices()->set(TestDirectory::class, $this->directory);

        return [$this->directory->add(new RefChatUser('ana', 'pub-ana', 'Ana')), $this->directory->add(new RefChatUser('bob', 'pub-bob', 'Bob'))];
    }

    private function userId(string $name): int
    {
        return (int) $this->connection()->fetchOne('SELECT id FROM test_user WHERE name = ?', [$name]);
    }

    /**
     * The foreign keys the mapping declares (`table.column -> table(column) rule`; no rule = NO ACTION).
     *
     * @return list<string>
     */
    private function mappedForeignKeys(): array
    {
        $keys = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            assert($metadata instanceof ClassMetadata);
            foreach ($metadata->associationMappings as $association) {
                if (!$association->isToOneOwningSide()) {
                    continue;
                }
                $target = $this->em->getClassMetadata($association->targetEntity);
                foreach ($association->joinColumns as $joinColumn) {
                    $keys[] = $metadata->getTableName() . '.' . $joinColumn->name . ' -> ' . $target->getTableName() . '(' . $joinColumn->referencedColumnName . ') ' . strtoupper($joinColumn->onDelete ?? 'NO ACTION');
                }
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * The foreign keys the server reports for the bundle's and the test's tables.
     *
     * @return list<string>
     */
    private function serverForeignKeys(): array
    {
        $mysql = $this->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $rows  = $this->connection()->fetchAllAssociative(
            'SELECT kcu.table_name AS s, kcu.column_name AS c, ccu.table_name AS t, ccu.column_name AS r, rc.delete_rule AS d'
            . ' FROM information_schema.referential_constraints rc'
            . ' JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema'
            . ' JOIN ' . ($mysql
                ? '(SELECT constraint_schema, constraint_name, referenced_table_name AS table_name, referenced_column_name AS column_name FROM information_schema.key_column_usage WHERE referenced_table_name IS NOT NULL)'
                : 'information_schema.constraint_column_usage') . ' ccu ON ccu.constraint_name = rc.constraint_name AND ccu.constraint_schema = rc.constraint_schema'
            . ' WHERE kcu.table_schema = ' . ($mysql ? 'DATABASE()' : 'current_schema()'),
        );
        $keys = array_map(static function (array $row): string {
            $rule = (string) $row['d'];

            return strtolower($row['s'] . '.' . $row['c'] . ' -> ' . $row['t'] . '(' . $row['r'] . ') ') . (in_array($rule, ['RESTRICT', 'NO ACTION'], true) ? 'NO ACTION' : $rule);
        }, $rows);
        sort($keys);

        return $keys;
    }
}

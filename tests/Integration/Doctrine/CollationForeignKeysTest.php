<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Doctrine\ChannelKeyCollationListener;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function assert;
use function ksort;
use function preg_match;
use function preg_match_all;
use function sort;
use function str_starts_with;

/**
 * 1.40.1 regression (FK loss): on MySQL / MariaDB the schema generated with ChannelKeyCollationListener has exactly the
 * foreign keys and indexes of the schema generated without it, on every table and in every `doctrine.user_reference`
 * mode. 1.26.4 to 1.40.0 replaced the collated tables through the DBAL 4.5+ editor and doctrine/orm 3.7 / 3.8 added the
 * deferred foreign keys to the old tables: `nowo_chat_conversation` lost its user foreign keys and, in `string` mode,
 * the message, mention, reaction, participant… tables lost theirs.
 *
 * No server is needed: the bundle's own entity manager configuration on a connection whose platform comes from the
 * configured server version.
 */
final class CollationForeignKeysTest extends TestCase
{
    /** One foreign key per mode that 1.26.4 to 1.40.0 lost. */
    private const LOST = [
        ChatUserReferences::RELATION => ['nowo_chat_conversation', '/FOREIGN KEY \(support_owner_id\) REFERENCES test_user \(id\) ON DELETE CASCADE$/'],
        ChatUserReferences::ID       => ['nowo_chat_participant', '/FOREIGN KEY \(conversation_id\) REFERENCES nowo_chat_conversation \(id\) ON DELETE CASCADE$/'],
        ChatUserReferences::STRING   => ['nowo_chat_message', '/FOREIGN KEY \(conversation_id\) REFERENCES nowo_chat_conversation \(id\) ON DELETE CASCADE$/'],
    ];

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function cases(): iterable
    {
        foreach (['MySQL 8.4' => ['8.4.0', 'utf8mb4_0900_bin'], 'MariaDB 11.4' => ['11.4.0-MariaDB', 'utf8mb4_nopad_bin']] as $server => [$version, $collation]) {
            foreach ([ChatUserReferences::RELATION, ChatUserReferences::ID, ChatUserReferences::STRING] as $mode) {
                yield $server . ', ' . $mode => [$version, $collation, $mode];
            }
        }
    }

    #[DataProvider('cases')]
    public function testTheListenerKeepsEveryForeignKeyAndIndex(string $serverVersion, string $collation, string $mode): void
    {
        $kernel = new TestKernel(['profiles' => ['default' => ($mode === ChatUserReferences::STRING ? ['user_class' => null] : []) + ['doctrine' => ['user_reference' => $mode]]]]);
        $kernel->boot();

        try {
            $em = $kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
            assert($em instanceof EntityManagerInterface);
            $with    = $this->sql($em, $serverVersion, true);
            $without = $this->sql($em, $serverVersion, false);
        } finally {
            $kernel->shutdown();
        }

        self::assertSame($this->keys($without), $this->keys($with));
        [$table, $foreignKey] = self::LOST[$mode];
        self::assertMatchesRegularExpression($foreignKey, $this->foreignKeyOf($this->keys($with)[$table] ?? []), $table);
        self::assertMatchesRegularExpression('/channel_key VARCHAR\(\d+\) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `' . $collation . '`/', $this->create($with, 'nowo_chat_conversation'));
        if ($mode === ChatUserReferences::STRING) {
            self::assertMatchesRegularExpression('/author_id VARCHAR\(\d+\) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `' . $collation . '`/', $this->create($with, 'nowo_chat_message'));
        }
    }

    /**
     * The CREATE statements of the bundle's schema on that server version, with or without the collation listener
     * (every other listener of the bundle's entity manager stays).
     *
     * @return list<string>
     */
    private function sql(EntityManagerInterface $em, string $serverVersion, bool $listener): array
    {
        $events = new EventManager();
        foreach ($em->getEventManager()->getAllListeners() as $event => $listeners) {
            foreach ($listeners as $object) {
                if ($listener || !$object instanceof ChannelKeyCollationListener) {
                    $events->addEventListener($event, $object);
                }
            }
        }
        $configuration = $em->getConfiguration();
        $manager       = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => $serverVersion], $configuration), $configuration, $events);

        return (new SchemaTool($manager))->getCreateSchemaSql($manager->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @param list<string> $keys
     */
    private function foreignKeyOf(array $keys): string
    {
        foreach ($keys as $key) {
            if (str_starts_with($key, 'ALTER TABLE') && preg_match('/FOREIGN KEY \((support_owner_id|conversation_id)\)/', $key) === 1) {
                return $key;
            }
        }

        return '';
    }

    /**
     * @param list<string> $sql
     */
    private function create(array $sql, string $table): string
    {
        foreach ($sql as $statement) {
            if (str_starts_with($statement, 'CREATE TABLE ' . $table . ' (')) {
                return $statement;
            }
        }
        self::fail('No CREATE TABLE ' . $table);
    }

    /**
     * The foreign keys (`ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY`) and the indexes of each table, from the schema
     * tool's own SQL (no DBAL API that differs between DBAL 3 and 4).
     *
     * @param list<string> $sql
     *
     * @return array<string, list<string>>
     */
    private function keys(array $sql): array
    {
        $keys = [];
        foreach ($sql as $statement) {
            if (preg_match('/^CREATE TABLE (\w+) \(/', $statement, $table) === 1) {
                $keys[$table[1]] ??= [];
                preg_match_all('/(?:UNIQUE |FULLTEXT )?INDEX \w+ \([^)]*\)/', $statement, $indexes);
                foreach ($indexes[0] as $index) {
                    $keys[$table[1]][] = $index;
                }
            } elseif (preg_match('/^ALTER TABLE (\w+) ADD CONSTRAINT \w+ FOREIGN KEY/', $statement, $table) === 1) {
                $keys[$table[1]][] = $statement;
            }
        }
        foreach ($keys as &$list) {
            sort($list);
        }
        unset($list);
        ksort($keys);

        return $keys;
    }
}

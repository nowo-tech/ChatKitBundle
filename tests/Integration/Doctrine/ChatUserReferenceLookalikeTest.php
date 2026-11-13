<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChannelKeyCollationListener;
use Nowo\ChatKitBundle\Doctrine\UserReferenceListener;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefChatUser;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefDirectory;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_keys;
use function assert;
use function bin2hex;
use function ltrim;
use function parse_url;
use function preg_match;
use function sprintf;

use const PHP_URL_PATH;

/**
 * 1.27 (REQ-SEC-006 on the user reference columns, `doctrine.user_reference: id` with string references): on a real
 * MySQL / MariaDB server the generated user columns are binary NO PAD, `bob`, `Bob`, `bób` are three people, and on a
 * table still created with a case- and accent-folding PAD SPACE collation the PHP re-checks keep them apart (participant
 * rows, settings, erasure). Same throwaway database as ChatChannelRaceTest:
 *
 *     CHAT_KIT_RACE_DATABASE_URL='mysql://root:pass@db:3306/race?serverVersion=8.4.0&charset=utf8mb4' vendor/bin/phpunit --filter ChatUserReferenceLookalikeTest
 */
final class ChatUserReferenceLookalikeTest extends TestCase
{
    private TestKernel $kernel;

    private EntityManagerInterface $em;

    private RefDirectory $directory;

    protected function setUp(): void
    {
        $url = TestKernel::databaseUrl();
        if ($url === null) {
            self::markTestSkipped('Set CHAT_KIT_RACE_DATABASE_URL to a server database.');
        }
        $database = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (preg_match('/(?:^|_)(?:test|race|throwaway)(?:_|$)/', $database) !== 1) {
            throw new RuntimeException(sprintf('Refusing to use database "%s": setup drops everything in it.', $database));
        }
        $this->kernel = new TestKernel(['profiles' => ['default' => ['user_class' => null, 'doctrine' => ['user_reference' => 'id']]]]);
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $tool     = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->directory = new RefDirectory();
        $this->services()->set(TestDirectory::class, $this->directory);
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testTheGeneratedUserColumnsAreExact(): void
    {
        $platform  = $this->em->getConnection()->getDatabasePlatform();
        $collation = ChannelKeyCollationListener::collationFor($platform);
        if (!$platform instanceof AbstractMySQLPlatform) {
            self::assertNull($collation, 'PostgreSQL and SQLite compare exactly');

            return;
        }
        foreach (UserReferenceListener::RELATIONS as $class => $relations) {
            $metadata = $this->em->getClassMetadata($class);
            foreach (array_keys($relations) as $relation) {
                self::assertSame($collation, $this->collation($metadata->getTableName(), $metadata->getColumnName($relation . 'Ref')), $class . '::' . $relation);
            }
        }
        $conversations = $this->em->getClassMetadata(ChatConversation::class)->getTableName();
        self::assertSame($collation, $this->collation($conversations, 'direct_user_low'));
        self::assertSame($collation, $this->collation($conversations, 'direct_user_high'));
    }

    public function testLookAlikeReferencesAreThreePeople(): void
    {
        [$ana, $bob, $bigB, $bob2] = $this->people();
        $chat                      = $this->chat();
        $one                       = $chat->openDirect($ana, $bob);
        $two                       = $chat->openDirect($ana, $bigB);
        $three                     = $chat->openDirect($ana, $bob2);
        self::assertCount(3, array_unique([$one->getUuid(), $two->getUuid(), $three->getUuid()]));
        $participants = $this->get(ChatParticipantRepository::class);
        self::assertNotNull($participants->findOneFor($one, $bob));
        self::assertNull($participants->findOneFor($one, $bigB));
        self::assertNull($participants->findOneFor($one, $bob2));
    }

    public function testAFoldingTableNeverMergesLookAlikes(): void
    {
        $connection = $this->em->getConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertNull(ChannelKeyCollationListener::collationFor($connection->getDatabasePlatform()));

            return;
        }
        // The columns as 1.24–1.26 created them on a host whose default collation folds case, accents and padding.
        foreach ([ChatParticipant::class => 'user_id', ChatUserSettings::class => 'user_id'] as $class => $column) {
            $connection->executeStatement(sprintf('ALTER TABLE %s MODIFY %s VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL', $this->em->getClassMetadata($class)->getTableName(), $column));
        }
        foreach (['author_id', 'deleted_by_id', 'pinned_by_id'] as $column) {
            $connection->executeStatement(sprintf('ALTER TABLE %s MODIFY %s VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL', $this->em->getClassMetadata(ChatMessage::class)->getTableName(), $column));
        }
        [$ana, $bob, $bigB] = $this->people();
        $chat               = $this->chat();
        $mine               = $chat->openDirect($ana, $bob);
        $chat->send($bob, $mine, 'from bob');
        $chat->send($bigB, $chat->openDirect($ana, $bigB), 'from Bob');
        $settings = $this->get(ChatUserSettingsRepository::class);
        $row      = $settings->forUser($bob);
        $row->setPushEnabled(true);
        $settings->save($row);
        $settings->reset();
        $this->em->clear();

        self::assertNull($this->get(ChatParticipantRepository::class)->findOneFor($mine, $bigB), 'the SQL row of bob is dropped by the re-check');
        self::assertNull($settings->forUser($bigB)->getId(), 'Bob never gets bob\'s settings');
        $this->get(ChatPersonalData::class)->erase($bob);
        self::assertSame(['Bob'], $connection->fetchFirstColumn(sprintf('SELECT author_id FROM %s WHERE author_id IS NOT NULL', $this->em->getClassMetadata(ChatMessage::class)->getTableName())), 'erasing bob keeps Bob\'s messages');
    }

    /**
     * @return array{RefChatUser, RefChatUser, RefChatUser, RefChatUser}
     */
    private function people(): array
    {
        $make = fn (string $reference, string $name): RefChatUser => $this->directory->add(new RefChatUser($reference, 'pub-' . bin2hex($reference), $name));

        return [$make('ana', 'Ana'), $make('bob', 'Bob lower'), $make('Bob', 'Bob upper'), $make('bób', 'Bob accent')];
    }

    private function collation(string $table, string $column): mixed
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
    }

    private function chat(): ChatServiceInterface
    {
        return $this->get(ChatServiceInterface::class);
    }

    private function services(): ContainerInterface
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
    private function get(string $id): object
    {
        $service = $this->services()->get($id);
        assert($service instanceof $id);

        return $service;
    }
}

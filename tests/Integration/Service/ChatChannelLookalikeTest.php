<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Doctrine\ChannelKeyCollationListener;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

use function array_map;
use function array_unique;
use function assert;
use function count;
use function ltrim;
use function parse_url;
use function preg_match;
use function sprintf;

use const PHP_URL_PATH;

/**
 * House rule LQ on a real server database: look-alike channel keys (`project:Acme` / `project:acme` / `project:ACME` /
 * `project:Ácme`) are distinct channels, also on a MySQL / MariaDB table still created with a case- and accent-folding
 * PAD SPACE collation (the PHP re-check). Needs a throwaway database (the setup drops everything in it; the name must
 * contain a `test`, `race` or `throwaway` part), the same one as ChatChannelRaceTest:
 *
 *     CHAT_KIT_RACE_DATABASE_URL='mysql://root:pass@db:3306/race?serverVersion=8.4.0&charset=utf8mb4' vendor/bin/phpunit --filter ChatChannelLookalikeTest
 */
final class ChatChannelLookalikeTest extends TestCase
{
    private const array KEYS = ['project:Acme', 'project:acme', 'project:ACME', 'project:Ácme'];

    private TestKernel $kernel;

    private EntityManagerInterface $em;

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
        $this->kernel = new TestKernel();
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $tool     = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    public function testTheGeneratedColumnIsExact(): void
    {
        $platform  = $this->em->getConnection()->getDatabasePlatform();
        $collation = ChannelKeyCollationListener::collationFor($platform);
        if (!$platform instanceof AbstractMySQLPlatform) {
            self::assertNull($collation, 'PostgreSQL and SQLite compare exactly');

            return;
        }
        self::assertSame($collation, $this->em->getConnection()->fetchOne(
            'SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->table(), 'channel_key'],
        ));
    }

    public function testLookAlikeKeysAreDistinctChannels(): void
    {
        $chat  = $this->chat();
        $ana   = $this->user('Ana');
        $uuids = array_map(static fn (string $key): string => $chat->openChannel($ana, new ChatChannel($key, $key))->getUuid(), self::KEYS);

        self::assertCount(count(self::KEYS), array_unique($uuids), 'one conversation per exact key');
        $repository = $this->repository();
        foreach (self::KEYS as $index => $key) {
            self::assertSame($uuids[$index], $repository->findChannel($key)?->getUuid(), $key);
        }
        self::assertNull($repository->findChannel('project:acme '), 'a padded key is refused, never matched');
        $bob    = $this->user('Bob');
        $listed = $repository->findListForUser($bob, ['project:acme'], false, 50);
        self::assertSame(['project:acme'], array_map(static fn (ChatConversation $conversation): ?string => $conversation->getChannelKey(), $listed));

        $acme = $repository->findChannel('project:acme');
        self::assertInstanceOf(ChatConversation::class, $acme);
        $this->em->persist(new ChatMessageMention($chat->send($ana, $acme, 'hello'), $bob));
        $this->em->flush();
        $mentions = $this->get(ChatMessageMentionRepository::class);
        self::assertSame(1, $mentions->countForUser($bob, ['project:acme'], false));
        self::assertSame(0, $mentions->countForUser($bob, ['project:Acme', 'project:ACME'], false));
        self::assertCount(1, $mentions->findForUser($bob, ['project:acme'], false, 10));
        self::assertSame([], $mentions->findForUser($bob, ['project:Acme'], false, 10));
    }

    public function testATableWithTheOldCollationNeverMergesLookAlikes(): void
    {
        $connection = $this->em->getConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertNull(ChannelKeyCollationListener::collationFor($connection->getDatabasePlatform()));

            return;
        }
        // The column as 1.0.0–1.26.3 created it on a host whose default_table_options collation is utf8mb4_unicode_ci
        // (case- and accent-insensitive, PAD SPACE).
        $connection->executeStatement(sprintf('ALTER TABLE %s MODIFY channel_key VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL', $this->table()));
        self::assertSame(1, (int) $connection->fetchOne("SELECT _utf8mb4'project:Acme' = _utf8mb4'project:acme ' COLLATE utf8mb4_unicode_ci"), 'the old collation folds case and padding');

        $chat = $this->chat();
        $ana  = $this->user('Ana');
        $bob  = $this->user('Bob');
        $acme = $chat->openChannel($ana, new ChatChannel('project:Acme', 'Acme'));
        $this->em->persist(new ChatMessageMention($chat->send($ana, $acme, 'hello'), $bob));
        $this->em->flush();

        $repository = $this->repository();
        self::assertNull($repository->findChannel('project:acme'), 'the SQL row of project:Acme is dropped by the re-check');
        self::assertSame([], $repository->findListForUser($bob, ['project:acme', 'project:ACME'], false, 50));
        $mentions = $this->get(ChatMessageMentionRepository::class);
        self::assertSame(0, $mentions->countForUser($bob, ['project:acme'], false));
        self::assertSame([], $mentions->findForUser($bob, ['project:acme'], false, 10));
        self::assertSame(1, $mentions->countForUser($bob, ['project:Acme'], false));
        self::assertCount(1, $mentions->findForUser($bob, ['project:Acme'], false, 10));

        try {
            $chat->openChannel($bob, new ChatChannel('project:acme', 'Other'));
            self::fail('A look-alike of an existing channel must not open it.');
        } catch (UniqueConstraintViolationException) {
            // Until the ALTER TABLE of UPGRADING.md, the old unique key refuses the look-alike: an error, never the other channel.
        }
        self::assertSame(1, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->table())));
    }

    private function table(): string
    {
        return $this->em->getClassMetadata(ChatConversation::class)->getTableName();
    }

    private function user(string $name): TestUser
    {
        $user = new TestUser($name, $name . '@example.test');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function chat(): ChatService
    {
        return $this->get(ChatService::class);
    }

    private function repository(): ChatConversationRepository
    {
        return $this->get(ChatConversationRepository::class);
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
        $services = $this->kernel->getContainer()->get('test.service_container');
        assert($services instanceof ContainerInterface);
        $service = $services->get($id);
        assert($service instanceof $id);

        return $service;
    }
}

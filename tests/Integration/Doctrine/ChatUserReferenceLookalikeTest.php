<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChannelKeyCollationListener;
use Nowo\ChatKitBundle\Doctrine\StringReferenceSchemaGuard;
use Nowo\ChatKitBundle\Doctrine\UserReferenceListener;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefChatUser;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefDirectory;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use LogicException;
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
 * table still created with a case- and accent-folding PAD SPACE collation the chat refuses to run (audit OJ-M1), and the
 * read paths stay exact even if the columns fold behind a process that already checked them. Same throwaway database as ChatChannelRaceTest:
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
        $this->kernel = new TestKernel(['profiles' => ['default' => ['user_class' => null, 'doctrine' => ['user_reference' => 'string']]]]);
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

    public function testAFoldingTableIsRefusedBeforeAnyReadOrWrite(): void
    {
        $connection = $this->em->getConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->get(StringReferenceSchemaGuard::class)->assertReady();
            self::assertTrue(true, 'PostgreSQL / SQLite compare exactly: nothing to check');

            return;
        }
        [$ana, $bob, $bigB] = $this->people();
        $chat = $this->chat();
        $mine = $chat->openDirect($ana, $bob);
        $chat->send($bob, $mine, 'from bob');
        $this->fold();
        $this->get(StringReferenceSchemaGuard::class)->reset();

        foreach ([
            'reaction' => fn () => $chat->toggleReaction($bigB, $chat->send($ana, $mine, 'x'), '👍'),
            'settings' => fn () => $this->get(ChatUserSettingsRepository::class)->forUser($bigB),
            'unread'   => fn () => $this->get(ChatMessageRepository::class)->countUnreadByConversation('Bob', [(int) $mine->getId()]),
            'direct'   => fn () => $chat->openDirect($ana, $bigB),
        ] as $what => $call) {
            try {
                $call();
                self::fail($what . ' ran on a folding column');
            } catch (LogicException $exception) {
                self::assertStringContainsString('still fold', $exception->getMessage(), $what);
            }
        }
        self::assertTrue($this->em->isOpen(), 'no unique-key clash closed the entity manager');
    }

    public function testTheReadPathsAreExactEvenIfTheColumnsFoldLater(): void
    {
        $connection = $this->em->getConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertNull(ChannelKeyCollationListener::collationFor($connection->getDatabasePlatform()));

            return;
        }
        [$ana, $bob, $bigB] = $this->people();
        $chat    = $this->chat();
        $withBob = $chat->openDirect($ana, $bob);
        $withBig = $chat->openDirect($ana, $bigB);
        $chat->send($ana, $withBob, 'hi @[Bob lower](' . $bob->getChatPublicId() . ')');
        $chat->send($ana, $withBig, 'hi @[Bob upper](' . $bigB->getChatPublicId() . ')');
        // Both in one channel: Bob writes and has read everything; bob has read nothing.
        $channel = $chat->openChannel($bob, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->openChannel($bigB, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->markRead($bigB, $channel, (int) $chat->send($bigB, $channel, 'from Bob')->getId());
        $this->get(StringReferenceSchemaGuard::class)->assertReady();
        // The schema folds again behind a process that already checked it (e.g. a later botched migration).
        $this->fold();
        $this->em->clear();
        $withBob = $this->em->getRepository(ChatConversation::class)->findOneBy(['uuid' => $withBob->getUuid()]) ?? self::fail('conversation');
        $withBig = $this->em->getRepository(ChatConversation::class)->findOneBy(['uuid' => $withBig->getUuid()]) ?? self::fail('conversation');

        $ids = [(int) $withBob->getId(), (int) $withBig->getId()];
        self::assertSame([(int) $channel->getId() => 1], $this->get(ChatMessageRepository::class)->countUnreadByConversation('bob', [(int) $channel->getId()]), 'Bob\'s message is foreign to bob, and Bob\'s read cursor is not bob\'s');
        $mentions = $this->get(ChatMessageMentionRepository::class);
        self::assertSame([(int) $withBob->getId() => 1], $mentions->countUnreadByConversation($bob, $ids));
        self::assertSame(1, $mentions->countForUser($bob, [], false));
        self::assertCount(1, $mentions->findForUser($bob, [], false, 10));
        self::assertSame([$withBob->getId()], array_map(static fn (ChatConversation $c): ?int => $c->getId(), $this->get(ChatConversationRepository::class)->findParticipating($bob, 10)));
        self::assertSame(['ana'], $this->get(ChatConversationRepository::class)->findDirectPeerIds($bob));
        self::assertSame([(int) $withBob->getId()], array_keys($this->get(ChatParticipantRepository::class)->findForUser($bob, $ids)));
        self::assertSame(1, $mentions->markReadUpTo($bob, $withBob, PHP_INT_MAX, new \DateTimeImmutable()));
        self::assertSame(1, $mentions->countForUser($bigB, [], false), 'Bob\'s mention stays unread');
    }

    private function fold(): void
    {
        // The columns as a host's default collation would create them: case-, accent- and padding-insensitive.
        $connection = $this->em->getConnection();
        foreach (UserReferenceListener::RELATIONS as $class => $relations) {
            $metadata = $this->em->getClassMetadata($class);
            foreach ($relations as $relation => $nullable) {
                $connection->executeStatement(sprintf('ALTER TABLE %s MODIFY %s VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci %s', $metadata->getTableName(), $metadata->getColumnName($relation . 'Ref'), $nullable ? 'NULL' : 'NOT NULL'));
            }
        }
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

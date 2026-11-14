<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\StringReferenceSchemaGuard;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefChatUser;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefDirectory;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function assert;
use function preg_quote;
use function preg_replace;
use function sprintf;

/**
 * 1.28 (`doctrine.user_reference: string`): what the chat does when the user columns fold behind a schema check that
 * already passed — a column altered, or a migration rolled back, after the check of the request (1.27.1: one check
 * per request and connection). The reads degrade (null, unsaved defaults) and warn; the writes that would follow are
 * refused before they clash with the look-alike's unique key inside the flush and close the entity manager.
 *
 * The folding column is real: SQLite's `COLLATE NOCASE` is to `bob` / `Bob` what a host's default
 * `utf8mb4_general_ci` is on MySQL. ChatUserReferenceLookalikeTest runs the same story on a MySQL / MariaDB server.
 */
final class StringReferenceLookAlikeDegradeTest extends TestCase
{
    private TestKernel $kernel;

    private EntityManagerInterface $em;

    private RefDirectory $directory;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel(['profiles' => ['default' => ['user_class' => null, 'doctrine' => ['user_reference' => 'string']]]]);
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->directory = new RefDirectory();
        $this->services()->set(TestDirectory::class, $this->directory);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testTheReadsDegradeAndTheWritesAreRefused(): void
    {
        $make = fn (string $reference, string $name): RefChatUser => $this->directory->add(new RefChatUser($reference, 'pub-' . $reference, $name));
        $ana  = $make('ana', 'Ana');
        $bob  = $make('bob', 'Bob lower');
        $bigB = $make('Bob', 'Bob upper');

        $chat    = $this->get(ChatServiceInterface::class);
        $withBob = $chat->openDirect($ana, $bob);
        $message = $chat->send($ana, $withBob, 'hi');
        $chat->toggleReaction($bob, $message, '👍');
        $settings = $this->get(ChatUserSettingsRepository::class);
        $settings->save($settings->forUser($bob));
        $this->get(StringReferenceSchemaGuard::class)->assertReady();

        // The schema folds again behind the passed check.
        foreach ([ChatParticipant::class, ChatReaction::class, ChatUserSettings::class] as $class) {
            $this->fold($this->em->getClassMetadata($class)->getTableName(), 'user_id');
        }
        $this->em->clear();
        $settings->reset();
        $withBob = $this->em->getRepository($withBob::class)->findOneBy(['uuid' => $withBob->getUuid()]) ?? self::fail('conversation');
        $message = $this->em->getRepository($message::class)->findOneBy(['uuid' => $message->getUuid()]) ?? self::fail('message');

        // The column really folds now: the database answers bob's row to a query for "Bob".
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant WHERE user_id = ?', ['Bob']));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_user_settings WHERE user_id = ?', ['Bob']));

        // Reads: Bob sees none of bob's rows, and nothing throws (this runs on every page of the host).
        self::assertNull($this->get(ChatParticipantRepository::class)->findOneFor($withBob, $bigB));
        self::assertNull($this->get(ChatReactionRepository::class)->findOneFor($message, $bigB, '👍'));
        $defaults = $settings->forUser($bigB);
        self::assertNull($defaults->getId(), 'unsaved defaults, not bob\'s row');
        self::assertNull($settings->forUsers([$bigB])['Bob']->getId(), 'the list path agrees with the single one');
        self::assertTrue($this->em->isOpen());

        // Writes for that user are refused one by one, and the entity manager stays open.
        foreach ([
            'participant' => static fn () => $chat->participant($withBob, $bigB),
            'reaction'    => static fn () => $chat->toggleReaction($bigB, $message, '👍'),
            'settings'    => static fn () => $settings->save($defaults),
        ] as $what => $write) {
            try {
                $write();
                self::fail($what . ' was written onto a look-alike\'s row');
            } catch (LogicException $exception) {
                self::assertStringContainsString('look-alike', $exception->getMessage(), $what);
                self::assertStringContainsString('user_id', $exception->getMessage(), $what);
            }
            self::assertTrue($this->em->isOpen(), $what . ': no clash closed the entity manager');
        }

        // Everybody else keeps working, in the same request: the automatic reply to Bob is skipped, not raised.
        self::assertNotNull($chat->send($ana, $withBob, 'still working')->getId());
    }

    public function testAnExactColumnWritesAsUsual(): void
    {
        $make = fn (string $reference, string $name): RefChatUser => $this->directory->add(new RefChatUser($reference, 'pub-' . $reference, $name));
        $ana  = $make('ana', 'Ana');
        $bob  = $make('bob', 'Bob lower');
        $bigB = $make('Bob', 'Bob upper');

        $chat    = $this->get(ChatServiceInterface::class);
        $withBob = $chat->openDirect($ana, $bob);
        $message = $chat->send($ana, $withBob, 'hi');
        $chat->toggleReaction($bob, $message, '👍');

        // Nothing was reported, so every write for the look-alike goes through on its own rows.
        $this->get(ChatParticipantRepository::class)->assertInsertable($bigB);
        $this->get(ChatReactionRepository::class)->assertInsertable($bigB);
        self::assertSame($bigB->getChatUserReference(), ChatUserReferences::key($chat->participant($withBob, $bigB)->getUser()));
        self::assertNotSame([], $chat->toggleReaction($bigB, $message, '👍'));
        $settings = $this->get(ChatUserSettingsRepository::class);
        $settings->save($settings->forUser($bigB));
        self::assertNotNull($settings->forUser($bigB)->getId());
        self::assertNull($settings->forUser($bob)->getId(), 'bob has no row of his own');
    }

    /**
     * Gives one column of one SQLite table a case-folding collation, keeping its rows and its indexes: what a host's
     * default collation does on MySQL / MariaDB. SQLite cannot alter a collation, so the table is rebuilt.
     */
    private function fold(string $table, string $column): void
    {
        $connection = $this->em->getConnection();
        $ddl        = (string) $connection->fetchOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);
        $indexes    = $connection->fetchFirstColumn('SELECT sql FROM sqlite_master WHERE type = ? AND tbl_name = ? AND sql IS NOT NULL', ['index', $table]);
        $folding    = (string) preg_replace('/\b' . preg_quote($column, '/') . '\b(\s+[A-Za-z]+(?:\(\d+\))?)/', $column . '$1 COLLATE NOCASE', $ddl, 1);
        $folding    = (string) preg_replace('/\b' . preg_quote($table, '/') . '\b/', $table . '_folding', $folding, 1);
        $connection->executeStatement($folding);
        $connection->executeStatement(sprintf('INSERT INTO %s_folding SELECT * FROM %s', $table, $table));
        $connection->executeStatement(sprintf('DROP TABLE %s', $table));
        $connection->executeStatement(sprintf('ALTER TABLE %s_folding RENAME TO %s', $table, $table));
        foreach ($indexes as $index) {
            $connection->executeStatement((string) $index);
        }
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

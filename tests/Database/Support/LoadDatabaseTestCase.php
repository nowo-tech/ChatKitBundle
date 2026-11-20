<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function assert;
use function ltrim;
use function parse_url;
use function preg_match;
use function sprintf;
use function str_starts_with;

use const PHP_URL_PATH;

/**
 * 1.30 (Beacon spec 239): the real-database load tests, on the server of CHAT_KIT_RACE_DATABASE_URL (MySQL 8.4 or
 * PostgreSQL 16 in CI, job "Load readiness"). The test's own connection is ChatKit's (a booted test kernel); a second
 * session in another process holds locks against it. Use a throwaway database: the setup drops everything in it and
 * refuses a name with no `test`, `race` or `throwaway` part. Without the variable the tests are skipped.
 */
abstract class LoadDatabaseTestCase extends TestCase
{
    /** The schema is created once per test process (a test helper, never a worker). */
    protected static bool $schema = false; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty

    protected TestKernel $kernel;

    protected EntityManagerInterface $em;

    protected ?SecondConnection $other = null;

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
        $this->kernel = new TestKernel();
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em   = $em;
        $connection = $em->getConnection();
        if (!self::$schema) {
            $tool = new SchemaTool($em);
            $tool->dropDatabase();
            $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
            $connection->executeStatement('CREATE TABLE load_ballast (id INT NOT NULL PRIMARY KEY)');
            self::$schema = true; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        }
        foreach (['load_ballast', 'nowo_chat_ticket_counter', 'nowo_chat_message_link', 'nowo_chat_message_mention', 'nowo_chat_reaction', 'nowo_chat_message', 'nowo_chat_conversation_link', 'nowo_chat_participant', 'nowo_chat_conversation', 'nowo_chat_quick_reply', 'nowo_chat_user_settings', 'test_user'] as $table) {
            $connection->executeStatement('DELETE FROM ' . $table);
        }
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    protected function mysql(): bool
    {
        return str_starts_with((string) TestKernel::databaseUrl(), 'mysql');
    }

    protected function connection(): Connection
    {
        return $this->em->getConnection();
    }

    protected function otherSession(): SecondConnection
    {
        return $this->other ??= new SecondConnection((string) TestKernel::databaseUrl());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function get(string $id): object
    {
        $service = $this->kernel->getContainer()->get($id);
        assert($service instanceof $id);

        return $service;
    }

    protected function user(string $name): TestUser
    {
        $user = new TestUser($name, $name . '@example.test');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * The test's connection gives up waiting for a row lock after about a second (lock wait timeout 1205 on MySQL,
     * lock_not_available 55P03 on PostgreSQL): both are transient failures.
     */
    protected function shortLockWait(): void
    {
        $this->connection()->executeStatement($this->mysql() ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");
    }

    protected function sleepStatement(float $seconds): string
    {
        return $this->mysql() ? sprintf('SELECT SLEEP(%F)', $seconds) : sprintf('SELECT pg_sleep(%F)', $seconds);
    }

    /**
     * Makes the second session the heavier transaction, so a MySQL deadlock picks the test's connection as the victim.
     */
    protected function ballast(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            self::assertSame('OK', $this->otherSession()->execute('INSERT INTO load_ballast (id) VALUES (' . $i . ')'));
        }
    }

    /**
     * What the worker's next request finds after a failure, once the request boundary ran (ChatKit's boundary listener:
     * a manager a failed chat flush closed is replaced there, never mid-request): no transaction left open on the test's
     * connection, the entity manager open, nothing pending.
     */
    protected function assertHealthy(): void
    {
        $boundary = $this->kernel->getContainer()->get(ChatRequestBoundaryListener::class);
        assert($boundary instanceof ChatRequestBoundaryListener);
        $boundary->clear();
        self::assertSame(0, $this->connection()->getTransactionNestingLevel(), 'no transaction left open');
        $registry = $this->kernel->getContainer()->get('doctrine');
        assert($registry instanceof ManagerRegistry);
        $manager = $registry->getManager();
        assert($manager instanceof EntityManagerInterface);
        self::assertTrue($manager->isOpen(), 'the entity manager is open');
        self::assertSame([], $manager->getUnitOfWork()->getScheduledEntityInsertions(), 'nothing pending');
    }

    protected function fresh(TestUser $user): TestUser
    {
        return $this->em->find(TestUser::class, $user->getId()) ?? self::fail('user');
    }

    protected function causedByDeadlock(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof DeadlockException) {
                return true;
            }
        }

        return false;
    }
}

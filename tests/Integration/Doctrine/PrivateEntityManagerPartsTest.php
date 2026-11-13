<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function assert;

/**
 * 1.27: the parts of the private entity manager — the registry routing, the boundary drop (manager dropped first, the
 * dropped changes logged by class and count only), the rollback bookkeeping, the savepoints that rethrow the original
 * exception, and the repositories (the bundle's service repositories, others per manager).
 */
final class PrivateEntityManagerPartsTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['doctrine' => ['private_entity_manager' => true]]]];
    }

    public function testTheRegistryRoutesOnlyTheChatEntitiesAndTheSourceName(): void
    {
        $registry = $this->get(ChatKitManagerRegistry::class);
        $private  = $this->get(ChatKitEntityManager::class);
        $doctrine = $this->kernel->getContainer()->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);

        self::assertSame($private, $registry->getManagerForClass(ChatMessage::class));
        self::assertSame($private, $registry->getManager());
        self::assertSame($private, $registry->getManager('default'));
        self::assertSame($this->em, $registry->getManagerForClass(TestUser::class));
        self::assertSame($doctrine->getManagers(), $registry->getManagers());
        self::assertSame($doctrine->getManagerNames(), $registry->getManagerNames());
        self::assertSame($doctrine->getConnectionNames(), $registry->getConnectionNames());
        self::assertSame($doctrine->getConnections(), $registry->getConnections());
        self::assertSame($doctrine->getConnection(), $registry->getConnection());
        self::assertSame('default', $registry->getDefaultConnectionName());
        self::assertSame('default', $registry->getDefaultManagerName());
        self::assertInstanceOf(ChatConversationRepository::class, $registry->getRepository(ChatConversation::class));
        self::assertSame($this->em->getRepository(TestUser::class), $registry->getRepository(TestUser::class));

        $private->live();
        self::assertSame($private, $registry->resetManager());
        self::assertFalse($private->isCreated(), 'resetting the source name drops the private manager only');
        self::assertTrue($this->em->isOpen());

        self::assertTrue(ChatKitManagerRegistry::isBundleEntity('Proxies\\__CG__\\Nowo\\ChatKitBundle\\Entity\\ChatMessage'));
        self::assertFalse(ChatKitManagerRegistry::isBundleEntity(TestUser::class));
        self::assertSame(ChatMessage::class, ChatKitManagerRegistry::realClass('Proxies\\__CG__\\' . ChatMessage::class));
    }

    public function testTheBoundaryDropsTheManagerFirstAndLogsOnlyClassesAndCounts(): void
    {
        $logger  = new ContextLogger();
        $private = new ChatKitEntityManager($this->registry(), 'default', $logger);
        $live    = $private->live();
        $user    = $this->user('Ana');
        $live->persist(ChatConversation::support($live->getReference(TestUser::class, $user->getId()) ?? self::fail('reference'), new DateTimeImmutable()));

        $private->reset();

        self::assertFalse($private->isCreated());
        self::assertSame([], $live->getUnitOfWork()->getScheduledEntityInsertions(), 'the dropped manager was cleared');
        self::assertCount(1, $logger->records);
        self::assertSame(['bundle' => 'nowo_chat_kit', 'count' => 1, 'changes' => [ChatConversation::class => 1]], $logger->records[0]['context']);
        $private->reset();
        self::assertCount(1, $logger->records, 'nothing to drop, nothing logged');
        self::assertNotSame($live, $private->live(), 'a fresh manager after the boundary');
    }

    public function testAFailingInspectionStillDropsTheManager(): void
    {
        $logger  = new ContextLogger(new RuntimeException('logger down'));
        $private = new ChatKitEntityManager($this->registry(), 'default', $logger);
        $live    = $private->live();
        $live->persist(ChatConversation::support($live->getReference(TestUser::class, $this->user('Ana')->getId()) ?? self::fail('reference'), new DateTimeImmutable()));

        $private->reset();

        self::assertFalse($private->isCreated());
    }

    public function testRollbacksMakeTheManagerStaleOnlyWhenTheyUndidItsRows(): void
    {
        $private = new ChatKitEntityManager($this->registry());
        $first   = $private->live();
        $private->connectionRolledBack(1);
        self::assertFalse($private->isStale(), 'nothing was written');

        $connection = $first->getConnection();
        $connection->beginTransaction();
        $private->noteWrite();
        $private->savepointReleased(2);
        $private->connectionRolledBack(2);
        self::assertFalse($private->isStale(), 'a deeper savepoint did not undo level 1');
        $private->connectionRolledBack(1);
        self::assertTrue($private->isStale());
        $connection->rollBack();
        self::assertNotSame($first, $private->live(), 'replaced on the next use');
        self::assertFalse($private->isStale());

        $connection->beginTransaction();
        $private->noteWrite();
        $connection->commit();
        $private->connectionCommitted();
        $private->connectionRolledBack(1);
        self::assertFalse($private->isStale(), 'committed rows are durable');
        $private->connectionRolledBack(null);
        self::assertFalse($private->isStale());
    }

    public function testTheMiddlewareTellsTheManagerAboutRollbacks(): void
    {
        self::assertTrue(PrivateEntityManagerPass::supportsRollbackMiddleware());
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        self::assertInstanceOf(PrivateEntityManagerMiddleware::class, $container->get(PrivateEntityManagerMiddleware::class));

        $private = $this->get(ChatKitEntityManager::class);
        $private->live();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $private->noteWrite();
        $connection->beginTransaction();
        $connection->rollBack();
        self::assertFalse($private->isStale(), 'a rolled-back savepoint above the write leaves the manager alone');
        $connection->rollBack();
        self::assertTrue($private->isStale(), 'the rollback of the level it wrote in makes it stale');
    }

    public function testSavepointsRethrowTheOriginalException(): void
    {
        $private    = $this->get(ChatKitEntityManager::class);
        $connection = $this->em->getConnection();
        self::assertSame(3, ChatKitSavepoint::write($this->em, static fn (): int => 3), 'the application manager: no savepoint');
        self::assertSame(4, ChatKitSavepoint::write($private, static fn (): int => 4), 'outside a transaction: no savepoint');

        $connection->beginTransaction();
        try {
            self::assertSame(5, ChatKitSavepoint::write($private, static fn (): int => 5));
            try {
                ChatKitSavepoint::write($private, fn (): int => $this->failingWrite($connection));
                self::fail('no exception');
            } catch (RuntimeException $exception) {
                self::assertSame('original', $exception->getMessage());
            }
            self::assertTrue($connection->isTransactionActive(), 'the application transaction is still usable');
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Writes, then fails with the exception the caller must see.
     */
    private function failingWrite(Connection $connection): int
    {
        $connection->executeStatement('UPDATE nowo_chat_conversation SET kind = kind');

        throw new RuntimeException('original');
    }

    private function registry(): ManagerRegistry
    {
        $doctrine = $this->kernel->getContainer()->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);

        return $doctrine;
    }
}

/**
 * Keeps the context of every record; optionally throws.
 */
final class ContextLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function __construct(private readonly ?RuntimeException $failure = null)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->failure instanceof RuntimeException) {
            throw $this->failure;
        }
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }
}

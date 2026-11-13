<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use RuntimeException;

use function count;

/**
 * 1.27: the rest of the private entity manager's contract — repositories of plain classes, refresh, transactions,
 * savepoint bookkeeping through the connection, mirrored SQL filters, a source that does not map the chat, the
 * registry's other managers, and a savepoint whose own rollback fails.
 */
final class PrivateEntityManagerDetailsTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['doctrine' => ['private_entity_manager' => true]]]];
    }

    public function testOnlyTheWrappedPropertyIsMagic(): void
    {
        $private = $this->get(ChatKitEntityManager::class);
        $this->expectException(LogicException::class);
        $private->__get('other');
    }

    public function testPlainRepositoriesAreKeptPerManager(): void
    {
        $private = $this->get(ChatKitEntityManager::class);
        $first   = $private->getRepository(TestUser::class);
        self::assertSame(EntityRepository::class, $first::class);
        self::assertSame($first, $private->getRepository(TestUser::class), 'bound to the decorator until the boundary');
        $live = $private->live()->getRepository(TestUser::class);
        self::assertSame($live, $private->live()->getRepository(TestUser::class), 'the private manager\'s own factory keeps it');
        $private->reset();
        self::assertNotSame($first, $private->getRepository(TestUser::class));
    }

    public function testRefreshTransactionsAndClear(): void
    {
        $ana          = $this->user('Ana');
        $chat         = $this->get(ChatServiceInterface::class);
        $conversation = $chat->openSupport($ana);
        $private      = $this->get(ChatKitEntityManager::class);
        $private->refresh($conversation);
        self::assertSame(7, $private->wrapInTransaction(static fn (): int => 7));
        // A row loaded inside a transaction is tracked like a write (a rollback of that level makes the map stale).
        $private->clear();
        $connection = $private->getConnection();
        $connection->beginTransaction();
        $private->getRepository(ChatConversation::class)->findAll();
        $connection->beginTransaction();
        $connection->commit();
        $connection->rollBack();
        self::assertTrue($private->isStale());
        $private->live();
        self::assertFalse($private->isStale());
        $private->reset();
        $private->clear();
        self::assertFalse($private->isCreated(), 'clear() never creates the manager');
    }

    public function testTheSourcesFiltersAreMirrored(): void
    {
        $chat = $this->get(ChatServiceInterface::class);
        $ana  = $this->user('Ana');
        $chat->openSupport($ana);
        $chat->openDirect($ana, $this->user('Luis'));
        $private = $this->get(ChatKitEntityManager::class);
        $count   = static fn (): int => count($private->getRepository(ChatConversation::class)->findAll());
        self::assertSame(2, $count());

        $this->em->getFilters()->enable('test_kind')->setParameterList('kinds', ['support']);
        $private->clear();
        self::assertSame(1, $count(), 'the filter and its list parameter reach the private manager');
        $this->em->getFilters()->getFilter('test_kind')->setParameterList('kinds', ['direct', 'channel']);
        $private->clear();
        self::assertSame(['direct'], array_map(static fn (ChatConversation $c): string => $c->getKind()->value, $private->getRepository(ChatConversation::class)->findAll()), 'a changed parameter is copied again');
        $this->em->getFilters()->getFilter('test_kind')->setParameter('scalar', 'x');
        $private->clear();
        self::assertSame(1, $count());
        $this->em->getFilters()->disable('test_kind');
        $private->clear();
        self::assertSame(2, $count(), 'disabled on the source, disabled on the private manager');
    }

    public function testASourceThatDoesNotMapTheChatIsRefused(): void
    {
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn(true);
        $source = $this->createStub(EntityManagerInterface::class);
        $source->method('getMetadataFactory')->willReturn($factory);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($source);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not map');
        (new ChatKitEntityManager($registry, 'other'))->live();
    }

    public function testTheRegistryLeavesOtherManagersToTheApplication(): void
    {
        $other = $this->createStub(EntityManagerInterface::class);
        $inner = $this->createMock(ManagerRegistry::class);
        $inner->method('getDefaultManagerName')->willReturn('default');
        $inner->method('getManager')->with('reports')->willReturn($other);
        $inner->expects(self::once())->method('resetManager')->with('reports')->willReturn($other);
        $registry = new ChatKitManagerRegistry($inner, $this->get(ChatKitEntityManager::class));

        self::assertSame($other, $registry->getManager('reports'));
        self::assertSame($other, $registry->resetManager('reports'));
    }

    public function testASavepointWhoseRollbackFailsStillRethrowsTheOriginal(): void
    {
        $private    = $this->get(ChatKitEntityManager::class);
        $connection = $private->getConnection();
        $connection->beginTransaction();
        try {
            ChatKitSavepoint::write($private, fn (): int => $this->endTheTransactionAndFail($connection));
            self::fail('no exception');
        } catch (RuntimeException $exception) {
            self::assertSame('original', $exception->getMessage());
        }
        self::assertFalse($connection->isTransactionActive());
    }

    private function endTheTransactionAndFail(Connection $connection): int
    {
        // The server ended the transaction (e.g. a deadlock victim): rolling back to the savepoint fails too.
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        throw new RuntimeException('original');
    }
}

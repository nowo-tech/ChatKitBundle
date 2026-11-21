<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\Transaction\HostEntityManagerRunner;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.30 (audit QD-M3, ported from the audit's PoC with the assertion inverted): with `doctrine.private_entity_manager:
 * true` and an application runner on the application's manager (DoctrineDeadlockRetryBundle 3.0's adapter does
 * `wrapInTransaction()` on it), a chat write still never flushes or commits the application's pending work
 * (REQ-ARCH-001 rule 6): ChatKit runs it on its own manager.
 */
#[Group('private-manager')]
final class HostRunnerPrivateManagerTest extends DatabaseTestCase
{
    protected function hostTransactionRunner(): bool
    {
        return true;
    }

    protected function hostFixtures(): bool
    {
        return true;
    }

    public function testAChatWriteNeverCommitsTheApplicationsPendingWork(): void
    {
        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $app = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $app);
        $ana  = $this->user('Ana');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $this->user('Luis'));

        $app->persist(new TestUser('Half-done', 'half@example.test'));
        $chat->send($ana, $dm, 'chat write');

        self::assertSame(0, (int) $app->getConnection()->fetchOne("SELECT COUNT(*) FROM test_user WHERE name = 'Half-done'"), 'the application\'s half-done work is not committed');
        self::assertSame(0, $this->get(HostEntityManagerRunner::class)->runs);
        self::assertSame(1, (int) $app->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'));
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Transaction;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransientStorageFailure;
use PDOException;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * 1.30 (Beacon spec 239, audits QD-M1 / QD-L1): ChatKit's runner on the application's (shared) manager. The application's
 * pending work is flushed on its own first; the chat unit runs in one transaction; after a failure only ChatKit's rows
 * are detached, the application's entities stay managed, and a manager the ORM closed stays closed (loud) until the
 * next request boundary replaces it.
 */
final class ChatKitWritesTest extends DatabaseTestCase
{
    public function testTheWorkIsFlushedInsideTheUnitOfWork(): void
    {
        $user = new TestUser('Ana', 'ana@example.test');

        $result = ChatKitWrites::runRecovering($this->runner(), $this->em, function () use ($user): string {
            $this->em->persist($user);

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertNotNull($user->getId(), 'flushed inside the unit of work');
    }

    public function testAttachReturnsTheManagedRowOrLoadsItAgain(): void
    {
        $ana          = $this->user('Ana');
        $conversation = $this->get(ChatService::class)->openDirect($ana, $this->user('Luis'));
        self::assertSame($conversation, ChatKitWrites::attach($this->em, $conversation), 'managed: the same object');
        self::assertSame($ana, ChatKitWrites::attach($this->em, $ana), 'a host object is never touched');

        $this->em->detach($conversation);
        $again = ChatKitWrites::attach($this->em, $conversation);
        self::assertNotSame($conversation, $again);
        self::assertSame($conversation->getId(), $again->getId());
        self::assertTrue($this->em->contains($again));

        $value = new stdClass();
        self::assertSame($value, ChatKitWrites::attach($this->em, $value), 'not mapped');
        $unsaved = ChatConversation::support(new TestUser('Bea'));
        self::assertSame($unsaved, ChatKitWrites::attach($this->em, $unsaved), 'no id yet');

        $this->em->detach($again);
        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_participant');
        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_conversation');
        $this->expectException(ChatNotFoundException::class);
        ChatKitWrites::attach($this->em, $conversation);
    }

    public function testTheApplicationsPendingWorkIsWrittenFirstAndSurvivesAFailedChatUnit(): void
    {
        if ($this->em instanceof ChatKitEntityManager) {
            self::markTestSkipped('The shared manager only (the private one never holds the application\'s work).');
        }
        $ana     = $this->user('Ana');
        $pending = new TestUser('Pending', 'pending@example.test');
        $this->em->persist($pending);
        $chatRow = new ChatQuickReply($ana, 'Hi', 'Hello');

        try {
            ChatKitWrites::runRecovering($this->runner(), $this->em, function () use ($chatRow): string {
                $this->em->persist($chatRow);
                $this->em->flush();

                return $this->failing(new RuntimeException('after the flush'))();
            });
            self::fail('the failure surfaces');
        } catch (RuntimeException $exception) {
            self::assertSame('after the flush', $exception->getMessage());
        }

        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertTrue($this->em->isOpen());
        self::assertTrue($this->em->contains($ana), 'the application\'s entities stay managed');
        self::assertTrue($this->em->contains($pending));
        self::assertFalse($this->em->contains($chatRow), 'the chat row of the failed unit is detached');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM test_user WHERE email = 'pending@example.test'"), 'the application\'s work was written on its own, before');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_quick_reply'));
    }

    public function testAFailedUnitDetachesTheChatRowsItChangedAndWhatPointsAtThem(): void
    {
        $ana     = $this->user('Ana');
        $agent   = $this->user('Agent');
        $chat    = $this->get(ChatService::class);
        $support = $chat->openSupport($ana);
        $other   = $chat->openSupport($this->user('Bea'));
        $dm      = $chat->openDirect($ana, $this->user('Luis'));
        $mine    = $this->get(ChatParticipantRepository::class)->findOneFor($support, $ana) ?? self::fail('row');
        $reply   = new ChatQuickReply($ana, 'Hi', 'Hello');
        $this->em->persist($reply);
        $this->em->flush();
        file_put_contents('/work/home/debug.txt', 'dm managed before: ' . var_export($this->em->contains($dm), true) . "\n");

        try {
            ChatKitWrites::runRecovering($this->runner(), $this->em, function () use ($support, $other, $agent): string {
                $support->assign($agent);
                $other->setSupportContext('/changed', null);

                return $this->failing(new RuntimeException('before the flush'))();
            });
            self::fail('the failure surfaces');
        } catch (RuntimeException) {
        }

        self::assertFalse($this->em->contains($support), 'an association changed in the failed unit');
        self::assertFalse($this->em->contains($other), 'a field changed in the failed unit');
        self::assertFalse($this->em->contains($mine), 'a row pointing at a detached one');
        self::assertTrue($this->em->contains($dm), 'an untouched chat row stays');
        self::assertTrue($this->em->contains($reply));
        self::assertTrue($this->em->contains($ana));
        self::assertNull(ChatKitWrites::attach($this->em, $support)->getAssignee(), 'loaded again as stored');
    }

    public function testAFailedFlushLeavesTheManagerClosedUntilTheBoundaryReplacesIt(): void
    {
        if ($this->em instanceof ChatKitEntityManager) {
            self::markTestSkipped('The shared manager only (the private one replaces itself).');
        }
        $ana    = $this->user('Ana');
        $runner = $this->runner();
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class {
            public function postPersist(PostPersistEventArgs $args): void
            {
                if ($args->getObject() instanceof ChatQuickReply) {
                    throw new DeadlockException(PdoDriverException::new(new PDOException('Deadlock found when trying to get lock')), null);
                }
            }
        });

        try {
            ChatKitWrites::runRecovering($runner, $this->em, fn (): null => $this->em->persist(new ChatQuickReply($ana, 'Hi', 'Hello')));
            self::fail('transient');
        } catch (TransientStorageFailure) {
        }
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertFalse($this->em->isOpen(), 'closed by the ORM, and left so: the application\'s next flush fails loudly (1.29)');

        $runner->reset();

        self::assertTrue($this->em->isOpen(), 'replaced at the next boundary');
        $runner->reset();
        self::assertTrue($this->em->isOpen(), 'an open manager is left alone');
    }

    /**
     * @return callable(): string
     */
    private function failing(Throwable $failure): callable
    {
        return static function () use ($failure): string {
            throw $failure;
        };
    }

    private function runner(): ChatKitTransactionRunner
    {
        $services = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $services);
        $runner = $services->get(ChatKitTransactionRunner::SERVICE_ID);
        self::assertInstanceOf(ChatKitTransactionRunner::class, $runner);

        return $runner;
    }
}

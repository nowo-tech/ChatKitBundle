<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Transaction;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\Transaction\CountingTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use PDOException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * 1.30 (Beacon spec 239): the unit-of-work helper of every ChatKit write: the manager is flushed inside the runner, a
 * replay is told so, a lost insert race runs once more (outside a caller's transaction only), and what a replay
 * captured is attached to the manager again.
 */
final class ChatKitWritesTest extends DatabaseTestCase
{
    public function testTheWorkIsFlushedInsideTheRunnerAndToldWhenItRunsAgain(): void
    {
        $runner = $this->recordingRunner();
        $user   = new TestUser('Ana', 'ana@example.test');

        $result = ChatKitWrites::runRecovering($runner, $this->em, function (bool $replay) use ($user): string {
            $this->em->persist($user);

            return $replay ? 'again' : 'first';
        });

        self::assertSame('first', $result);
        self::assertSame(1, $runner->runs);
        self::assertNotNull($user->getId(), 'flushed inside the unit of work');
    }

    public function testALostInsertRaceRunsOnceMoreAsAReplay(): void
    {
        $runner = $this->recordingRunner();
        $seen   = [];

        $result = ChatKitWrites::runRecovering($runner, $this->em, function (bool $replay) use (&$seen): string {
            $seen[] = $replay;
            if (!$replay) {
                throw $this->lostRace();
            }

            return 'read the winner\'s row';
        }, true);

        self::assertSame('read the winner\'s row', $result);
        self::assertSame([false, true], $seen);
        self::assertSame(2, $runner->runs);
    }

    public function testALostInsertRaceSurfacesWithoutTheFlagOrInsideACallersTransaction(): void
    {
        $lost = $this->lostRace();
        try {
            ChatKitWrites::runRecovering($this->recordingRunner(), $this->em, $this->failing($lost));
            self::fail('no retry without the flag');
        } catch (UniqueConstraintViolationException $exception) {
            self::assertSame($lost, $exception);
        }

        $runner = $this->recordingRunner();
        $this->em->getConnection()->beginTransaction();
        try {
            ChatKitWrites::runRecovering($runner, $this->em, $this->failing($lost), true);
            self::fail('a caller\'s snapshot may not show the winner\'s row: no retry');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, $runner->runs);
        } finally {
            // The test's own level-1 transaction on SQLite (no deadlock, no lost savepoint).
            $this->em->getConnection()->rollBack(); // @phpstan-ignore frankenphp.worker.noRollbackAfterNestedBegin
        }

        $other = new RuntimeException('not a race');
        try {
            ChatKitWrites::runRecovering($this->recordingRunner(), $this->em, $this->failing($other), true);
            self::fail('only a unique key violation runs again');
        } catch (RuntimeException $exception) {
            self::assertSame($other, $exception);
        }
    }

    public function testAttachReturnsTheManagedInstanceOrLoadsItAgain(): void
    {
        $ana          = $this->user('Ana');
        $conversation = $this->get(ChatService::class)->openDirect($ana, $this->user('Luis'));
        self::assertSame($conversation, ChatKitWrites::attach($this->em, $conversation), 'managed: the same object');
        self::assertSame($ana, ChatKitWrites::attach($this->em, $ana));

        $this->em->clear();
        $again = ChatKitWrites::attach($this->em, $conversation);
        self::assertNotSame($conversation, $again);
        self::assertSame($conversation->getId(), $again->getId());
        self::assertTrue($this->em->contains($again));
        $user = ChatKitWrites::attach($this->em, $ana);
        if ($this->em instanceof ChatKitEntityManager) {
            self::assertSame($ana, $user, 'the private manager adopts host objects at flush');
        } else {
            self::assertTrue($this->em->contains($user), 'a host entity becomes the manager\'s reference');
            self::assertSame($ana->getId(), $user->getId());
        }

        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_participant');
        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_conversation');
        $this->em->clear();
        $this->expectException(ChatNotFoundException::class);
        ChatKitWrites::attach($this->em, $conversation);
    }

    public function testWhatIsNotAStoredEntityOfTheManagerIsReturnedUnchanged(): void
    {
        $value = new stdClass();
        self::assertSame($value, ChatKitWrites::attach($this->em, $value), 'not mapped');
        $unsaved = ChatConversation::support(new TestUser('Ana'));
        self::assertSame($unsaved, ChatKitWrites::attach($this->em, $unsaved), 'no id yet');

        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $private = new ChatKitEntityManager($registry);
        $ana     = $this->user('Ana');
        self::assertSame($ana, ChatKitWrites::attach($private, $ana), 'the private manager adopts host objects at flush');
    }

    public function testCarryKeepsAManagedObjectAndCopiesTheChangesOfAStoredOne(): void
    {
        $reply = new ChatQuickReply($this->user('Ana'), 'Hi', 'Hello');
        $this->em->persist($reply);
        $this->em->flush();
        self::assertSame($reply, ChatKitWrites::carry($this->em, $reply, true), 'managed: the same object');

        $this->em->clear();
        $reply->change('Hey', 'Hey there');
        $fresh = ChatKitWrites::carry($this->em, $reply, true);
        self::assertNotSame($reply, $fresh);
        self::assertTrue($this->em->contains($fresh));
        self::assertSame('Hey there', $fresh->getBody(), 'the caller\'s change, on the stored row');
        self::assertSame($reply->getId(), $fresh->getId());
    }

    public function testTheDefaultRunnerLeavesNothingBehindAFailure(): void
    {
        $runner = new ChatKitTransactionRunner($this->em);
        $user   = new TestUser('Pending', 'pending@example.test');
        try {
            ChatKitWrites::runRecovering($runner, $this->em, function () use ($user): string {
                $this->em->persist($user);
                $this->em->flush();

                return $this->failing(new RuntimeException('after the flush'))();
            });
            self::fail('the failure surfaces');
        } catch (RuntimeException $exception) {
            self::assertSame('after the flush', $exception->getMessage());
        }

        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->contains($user), 'nothing of the failed unit of work stays');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM test_user WHERE email = 'pending@example.test'"));
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

    private function lostRace(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('UNIQUE constraint failed')), null);
    }

    private function recordingRunner(): CountingTransactionRunner
    {
        return new CountingTransactionRunner(new ChatKitTransactionRunner($this->em));
    }
}

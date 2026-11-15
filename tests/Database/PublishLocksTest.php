<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Doctrine\ORM\Events;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Database\Support\SecondConnection;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;

use function microtime;
use function preg_match;
use function str_starts_with;

/**
 * 1.30 (Beacon spec 239), two sessions on MySQL 8.4 / PostgreSQL 16: a new message is one unit of work that locks its
 * conversation row first. Two messages of one conversation queue instead of deadlocking (up to 1.29 both took the
 * foreign-key share lock with the message insert and then both asked for the row's exclusive lock); a lock wait or a
 * deadlock answers TransientStorageFailure (503) and leaves the worker's connection healthy — level 0 even after a
 * deadlock inside the ORM's nested flush, where the savepoint is gone (MySQL 1305).
 */
final class PublishLocksTest extends LoadDatabaseTestCase
{
    public function testTwoMessagesOfOneConversationQueueInsteadOfDeadlocking(): void
    {
        [$ana, $luis, $dm] = $this->conversation();
        $peer              = $this->otherSession();
        // A concurrent send in the 1.30 order: the conversation row first, then its message.
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute('SELECT id FROM nowo_chat_conversation WHERE id = ' . $dm->getId() . ' FOR UPDATE'));
        self::assertSame('OK', $peer->execute("INSERT INTO nowo_chat_message (uuid, conversation_id, author_id, kind, body, body_html, created_at) VALUES ('00000000-0000-7000-8000-0000000000aa', " . $dm->getId() . ', ' . $luis->getId() . ", 'text', 'peer', '<p>peer</p>', '2026-11-15 10:00:00')"));
        $peer->send($this->sleepStatement(1.0));
        $peer->send('COMMIT');

        $started = microtime(true);
        $message = $this->get(ChatService::class)->send($ana, $dm, 'mine');

        self::assertGreaterThan(0.5, microtime(true) - $started, 'it waited for the peer\'s row lock');
        self::assertNotNull($message->getId());
        self::assertSame('OK', $peer->await(), 'the sleep');
        self::assertSame('OK', $peer->await(), 'the peer committed');
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'));
        $this->assertHealthy();
    }

    public function testALockWaitOnTheConversationIsTransientAndWritesNothing(): void
    {
        [$ana, , $dm] = $this->conversation();
        $peer         = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute('SELECT id FROM nowo_chat_conversation WHERE id = ' . $dm->getId() . ' FOR UPDATE'));
        $this->shortLockWait();

        try {
            $this->get(ChatService::class)->send($ana, $dm, 'waits');
            self::fail('the conversation row is locked by the peer');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(TransientStorageFailure::DEFAULT_MESSAGE, $failure->getMessage(), 'no SQL in the message');
        }
        $this->assertHealthy();
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'));

        self::assertSame('OK', $peer->execute('ROLLBACK'));
        self::assertNotNull($this->get(ChatService::class)->send($this->fresh($ana), $this->em->find(ChatConversation::class, $dm->getId()) ?? self::fail('dm'), 'now')->getId());
        $this->assertHealthy();
    }

    public function testADeadlockInTheMessagesFlushIsTransientAndTheNextSendWorks(): void
    {
        [$ana, , $dm] = $this->conversation();
        $peer         = $this->otherSession();
        // The peer holds the author's row (the message insert checks its foreign key against it) and, after a moment,
        // asks for the conversation row, which this send locks first: a cycle.
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute('SELECT id FROM test_user WHERE id = ' . $ana->getId() . ' FOR UPDATE'));
        $this->ballast();
        $peer->send($this->sleepStatement(0.5));
        $peer->send('SELECT id FROM nowo_chat_conversation WHERE id = ' . $dm->getId() . ' FOR UPDATE');

        $failed = false;
        try {
            $this->get(ChatService::class)->send($ana, $dm, 'deadlocked');
        } catch (TransientStorageFailure $failure) {
            $failed = true;
            self::assertTrue($this->causedByDeadlock($failure), 'the deadlock, not a lock wait');
        }
        self::assertSame('OK', $peer->await(), 'the sleep');
        $peerAnswer = $peer->await();
        self::assertSame($failed, str_starts_with($peerAnswer, 'OK'), 'exactly one side lost the deadlock: ' . $peerAnswer);
        self::assertTrue($failed || preg_match('/^ERR (1213|\\d+ 40P01)/', $peerAnswer) === 1, $peerAnswer);
        self::assertSame('OK', $peer->execute('ROLLBACK'));
        $this->assertHealthy();
        self::assertSame($failed ? 0 : 1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'), 'all or nothing');

        // The next request of this worker: a normal send on the same connection.
        $next = $this->get(ChatService::class)->send($this->fresh($ana), $this->em->find(ChatConversation::class, $dm->getId()) ?? self::fail('dm'), 'next');
        self::assertNotNull($next->getId());
        $this->assertHealthy();
    }

    public function testTwoFirstMessagesToOnePairOpenTheSameConversation(): void
    {
        $ana  = $this->user('ana');
        $luis = $this->user('luis');
        $peer = $this->otherSession();
        // Between this request's lookup and its insert, another request opens the pair's conversation and commits.
        $key = ChatConversation::direct($ana, $luis)->getDirectKey();
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class($peer, (string) $key) {
            private bool $done = false;

            public function __construct(private readonly SecondConnection $peer, private readonly string $key)
            {
            }

            public function onFlush(): void
            {
                if (!$this->done) {
                    $this->done = true;
                    TestCase::assertSame('OK', $this->peer->execute("INSERT INTO nowo_chat_conversation (uuid, kind, direct_key, last_message_at, created_at) VALUES ('00000000-0000-7000-8000-0000000000bb', 'direct', '" . $this->key . "', '2026-11-15 10:00:00', '2026-11-15 10:00:00')"));
                }
            }
        });

        $conversation = $this->get(ChatService::class)->openDirect($ana, $luis);

        self::assertSame('00000000-0000-7000-8000-0000000000bb', $conversation->getUuid(), 'the conversation the other request wrote');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation'));
        $this->assertHealthy();
    }

    /**
     * @return array{0: TestUser, 1: TestUser, 2: ChatConversation}
     */
    private function conversation(): array
    {
        $ana  = $this->user('ana');
        $luis = $this->user('luis');

        return [$ana, $luis, $this->get(ChatService::class)->openDirect($ana, $luis)];
    }
}

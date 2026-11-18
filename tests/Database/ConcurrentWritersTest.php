<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Doctrine\DBAL\ParameterType;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use RuntimeException;

use function is_resource;
use function json_decode;
use function microtime;
use function proc_close;
use function proc_open;
use function sort;
use function stream_get_contents;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * 1.30 (Beacon spec 239), ported from audit QD's stress run: two worker processes write one conversation at the same
 * time. On MySQL 1.29 lost about one send in fifteen to a deadlock (500) and left the worker with a closed entity
 * manager; 1.30 queues them on the conversation's row lock. Every call succeeds, and no worker is left with an open
 * transaction or a closed manager.
 */
final class ConcurrentWritersTest extends LoadDatabaseTestCase
{
    private const int CALLS = 60;

    public function testTwoSendersOnOneConversation(): void
    {
        $this->seed();
        [$a, $b] = $this->race('send');

        foreach ([$a, $b] as $writer) {
            self::assertSame(self::CALLS, $writer['ok'], 'failures: ' . json_encode($writer['failures']));
            self::assertSame(0, $writer['poisoned']);
            self::assertSame(0, $writer['closed']);
        }
        self::assertSame(1 + 2 * self::CALLS, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'));
    }

    public function testASenderAgainstReadsReactionsAndArchives(): void
    {
        $this->seed();
        [$a, $b] = $this->race('send', 'mixed');

        foreach ([$a, $b] as $writer) {
            self::assertSame(self::CALLS, $writer['ok'], 'failures: ' . json_encode($writer['failures']));
            self::assertSame(0, $writer['poisoned']);
            self::assertSame(0, $writer['closed']);
        }
    }

    /**
     * 1.35: the message menu's writes (save, unsave, mark unread, read with read times) against a sender of the same
     * conversation: every call succeeds, nothing is left open, and no message is saved twice.
     */
    public function testTheMessageMenuAgainstASender(): void
    {
        $this->seed();
        [$a, $b] = $this->race('send', 'menu');

        foreach ([$a, $b] as $writer) {
            self::assertSame(self::CALLS, $writer['ok'], 'failures: ' . json_encode($writer['failures']));
            self::assertSame(0, $writer['poisoned']);
            self::assertSame(0, $writer['closed']);
        }
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM (SELECT message_id FROM nowo_chat_saved_message GROUP BY user_id, message_id HAVING COUNT(*) > 1) d'));
    }

    /**
     * 1.35 (audit TK I3): the same user saving the same message from two workers at the cap: one row, no failure.
     */
    public function testTwoSavesAtTheCap(): void
    {
        $this->seed();
        [$a, $b] = $this->race('savecap', 'savecap');
        $this->assertClean($a, $b);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_saved_message'));
    }

    /**
     * 1.35 (audit TK-7): "mark unread" in one tab against reads in another: no lost update, and the receipt other
     * people see is never behind the reader's own read cursor.
     */
    public function testMarkUnreadAgainstReads(): void
    {
        $this->seed();
        $this->moreMessages(3);
        [$a, $b] = $this->race('unread', 'readonly');
        $this->assertClean($a, $b);
        $row = $this->connection()->fetchAssociative('SELECT last_read_message_id, receipt_message_id FROM nowo_chat_participant WHERE user_id = (SELECT id FROM test_user WHERE name = ?)', ['luis']);
        self::assertIsArray($row);
        self::assertTrue($row['receipt_message_id'] === null || (int) $row['receipt_message_id'] >= (int) $row['last_read_message_id'], (string) json_encode($row));
    }

    /**
     * 1.35 (audit TK-4): "Share read times" turned off and on against reads: every call succeeds, nothing is left open.
     */
    public function testOptOutAgainstReads(): void
    {
        $this->seed();
        [$a, $b] = $this->race('optout', 'readonly');
        $this->assertClean($a, $b);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT share_read_times FROM nowo_chat_user_settings WHERE user_id = (SELECT id FROM test_user WHERE name = ?)', ['luis']));
    }

    /**
     * 1.36 (REQ-RUNTIME-003 rule 7): two workers send the same forward under one `Idempotency-Key`: one copy, and every
     * call answers (the second one with the first one's copy).
     */
    public function testOneForwardUnderOneKey(): void
    {
        $this->seed();
        [$a, $b] = $this->race('forward', 'forward');
        $this->assertClean($a, $b);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message WHERE forwarded = ?', [true], [ParameterType::BOOLEAN]));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_idempotency'));
    }

    /**
     * 1.36: concurrent replies in one thread (and a sender in the conversation): the root counts every reply.
     */
    public function testConcurrentRepliesKeepTheCount(): void
    {
        $this->seed();
        [$a, $b] = $this->race('thread', 'thread');
        $this->assertClean($a, $b);
        $root = $this->connection()->fetchAssociative('SELECT id, thread_reply_count FROM nowo_chat_message WHERE thread_root_id IS NULL ORDER BY id LIMIT 1');
        self::assertIsArray($root);
        self::assertSame(2 * self::CALLS, (int) $root['thread_reply_count']);
        self::assertSame(2 * self::CALLS, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message WHERE thread_root_id = ?', [$root['id']]));
    }

    /**
     * 1.36: concurrent edits of one message: each previous text is kept once, none is lost.
     */
    public function testConcurrentEditsKeepEveryPreviousText(): void
    {
        $this->seed();
        [$a, $b] = $this->race('edit', 'edit');
        $this->assertClean($a, $b);
        $texts = $this->connection()->fetchFirstColumn('SELECT body FROM nowo_chat_message_revision ORDER BY id');
        $final = (string) $this->connection()->fetchOne('SELECT body FROM nowo_chat_message WHERE thread_root_id IS NULL ORDER BY id LIMIT 1');
        $all   = [...$texts, $final];
        sort($all);
        $expected = ['seed'];
        for ($i = 0; $i < self::CALLS; ++$i) {
            $expected[] = 'a edit ' . $i;
            $expected[] = 'b edit ' . $i;
        }
        sort($expected);
        self::assertSame($expected, $all);
    }

    /**
     * @param array{ok: int, failures: array<string, int>, poisoned: int, closed: int} ...$writers
     */
    private function assertClean(array ...$writers): void
    {
        foreach ($writers as $writer) {
            self::assertSame(self::CALLS, $writer['ok'], 'failures: ' . json_encode($writer['failures']));
            self::assertSame(0, $writer['poisoned']);
            self::assertSame(0, $writer['closed']);
        }
    }

    private function moreMessages(int $count): void
    {
        $chat  = $this->get(ChatService::class);
        $users = $this->em->getRepository(TestUser::class);
        $ana   = $users->findOneBy(['name' => 'ana']) ?? self::fail('ana');
        $luis  = $users->findOneBy(['name' => 'luis']) ?? self::fail('luis');
        for ($i = 0; $i < $count; ++$i) {
            $chat->send($ana, $chat->openDirect($ana, $luis), 'more ' . $i);
        }
    }

    private function seed(): void
    {
        $ana  = $this->user('ana');
        $luis = $this->user('luis');
        $chat = $this->get(ChatService::class);
        $chat->send($ana, $chat->openDirect($ana, $luis), 'seed');
    }

    /**
     * @return array{array{ok: int, failures: array<string, int>, poisoned: int, closed: int}, array{ok: int, failures: array<string, int>, poisoned: int, closed: int}}
     */
    private function race(string $modeA, string $modeB = 'send'): array
    {
        $start = (string) (microtime(true) + 2.0);
        $a     = $this->start([$modeA, (string) self::CALLS, 'a', $start]);
        $b     = $this->start([$modeB, (string) self::CALLS, 'b', $start]);

        return [$this->finish($a), $this->finish($b)];
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{handle: resource, stdout: resource}
     */
    private function start(array $arguments): array
    {
        $pipes = [];
        // Test-only helper process; the CI job has a timeout.
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
        $handle = proc_open([PHP_BINARY, __DIR__ . '/../Database/Support/concurrent-writer.php', ...$arguments], [1 => ['pipe', 'w']], $pipes);
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not start a writer.');
        }

        return ['handle' => $handle, 'stdout' => $pipes[1]];
    }

    /**
     * @param array{handle: resource, stdout: resource} $process
     *
     * @return array{ok: int, failures: array<string, int>, poisoned: int, closed: int}
     */
    private function finish(array $process): array
    {
        $output = (string) stream_get_contents($process['stdout']);
        proc_close($process['handle']);
        /** @var array{ok: int, failures: array<string, int>, poisoned: int, closed: int} $result */
        $result = json_decode($output, true, 8, JSON_THROW_ON_ERROR);

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use RuntimeException;

use function is_resource;
use function json_decode;
use function microtime;
use function proc_close;
use function proc_open;
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

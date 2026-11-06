<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;
use function is_resource;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Audit GZ-L1, GZ-L3, GZ-M1 and GZ-I7: real races between two processes through ChatService::openChannel(). They need a
 * server database, so they run only with one. Use a throwaway database: the setup drops everything in it, and refuses a
 * name with no `test`, `race` or `throwaway` part. For example:
 *
 *     CHAT_KIT_RACE_DATABASE_URL='mysql://root:pass@db:3306/race?serverVersion=8.4.0&charset=utf8mb4' vendor/bin/phpunit --filter ChatChannelRaceTest
 *
 * Add CHAT_KIT_RACE_ID_GENERATION=sequence on Postgres for ids from a sequence.
 */
final class ChatChannelRaceTest extends TestCase
{
    private const int ROUNDS = 8;

    protected function setUp(): void
    {
        if (TestKernel::databaseUrl() === null) {
            self::markTestSkipped('Set CHAT_KIT_RACE_DATABASE_URL to a server database.');
        }
        $this->script('setup');
    }

    public function testARowLockInTheListenerHoldsACountBasedLimit(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $this->script('reset');
            [$ana, $bob] = $this->race("project:a{$round}", "project:b{$round}", 'locked');

            $results = [$ana['result'], $bob['result']];
            sort($results);
            self::assertSame(['denied', 'opened'], $results, "round {$round}");
            self::assertSame(1, $this->rows(), "round {$round}");
            self::assertTrue($ana['emOpen'] && $bob['emOpen'], "round {$round}");
        }
    }

    public function testTheLoserOfTwoFirstOpeningsGetsTheSameChannel(): void
    {
        foreach (['none', 'sleep', 'locked', 'pending'] as $mode) {
            for ($round = 1; $round <= self::ROUNDS; ++$round) {
                $this->script('reset');
                [$ana, $bob] = $this->race('project:same', 'project:same', $mode);

                self::assertSame('opened', $ana['result'], "{$mode} round {$round}");
                self::assertSame('opened', $bob['result'], "{$mode} round {$round}");
                self::assertSame($ana['uuid'], $bob['uuid'], "{$mode} round {$round}");
                self::assertTrue($ana['emOpen'] && $bob['emOpen'], "{$mode} round {$round}");
                self::assertSame(1, $this->rows(), "{$mode} round {$round}");
            }
        }
    }

    public function testAListenersFlushedWriteSurvivesLosingTheRace(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $this->script('reset');
            [$ana, $bob] = $this->race('project:same', 'project:same', 'write');

            self::assertSame(['opened', 'opened'], [$ana['result'], $bob['result']], "round {$round}");
            self::assertSame(2, $this->rows('race_log'), "round {$round}: the loser's write is kept too");
            self::assertSame(1, $this->rows(), "round {$round}");
        }
    }

    public function testACallersTransactionSurvivesLosingTheRace(): void
    {
        $mysql = str_starts_with((string) TestKernel::databaseUrl(), 'mysql');
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $this->script('reset');
            $outcomes = $this->race('project:same', 'project:same', 'outer');
            foreach ($outcomes as $outcome) {
                self::assertTrue($outcome['committed'], "round {$round}");
                self::assertTrue($outcome['emOpen'], "round {$round}");
                // MySQL's repeatable read hides the winner's row from the loser's own transaction; no false refusal.
                self::assertContains($outcome['result'], $mysql ? ['opened', UniqueConstraintViolationException::class] : ['opened'], "round {$round}");
            }
            self::assertSame(1, $this->rows(), "round {$round}");
        }
    }

    /**
     * @return array{array{result: string, uuid: string|null, emOpen: bool, committed: bool}, array{result: string, uuid: string|null, emOpen: bool, committed: bool}}
     */
    private function race(string $anaKey, string $bobKey, string $mode): array
    {
        $start = (string) (microtime(true) + 1.5);
        $ana   = $this->start(['open', 'ana@example.test', $anaKey, $mode, $start]);
        $bob   = $this->start(['open', 'bob@example.test', $bobKey, $mode, $start]);

        return [$this->finish($ana), $this->finish($bob)];
    }

    private function rows(string $table = 'nowo_chat_conversation'): int
    {
        $process = $this->start(['count', $table]);
        $output  = (string) stream_get_contents($process['stdout']);
        fclose($process['stdout']);
        proc_close($process['handle']);

        return (int) $output;
    }

    private function script(string $command): void
    {
        $process = $this->start([$command]);
        fclose($process['stdout']);
        $status = proc_close($process['handle']);
        self::assertSame(0, $status, "{$command} failed");
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{handle: resource, stdout: resource}
     */
    private function start(array $arguments): array
    {
        $pipes = [];
        // Local-only test: each child ends with its database lock wait, and the caller's run has a deadline.
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout
        $handle = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/race/open_channel.php', ...$arguments], [1 => ['pipe', 'w']], $pipes);
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not start the race script.');
        }

        return ['handle' => $handle, 'stdout' => $pipes[1]];
    }

    /**
     * @param array{handle: resource, stdout: resource} $process
     *
     * @return array{result: string, uuid: string|null, emOpen: bool, committed: bool}
     */
    private function finish(array $process): array
    {
        $output = (string) stream_get_contents($process['stdout']);
        fclose($process['stdout']);
        proc_close($process['handle']);
        /** @var array{result: string, uuid: string|null, emOpen: bool, committed: bool} $decoded */
        $decoded = json_decode($output, true, 4, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

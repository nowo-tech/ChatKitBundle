<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;
use function is_resource;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * Audit GZ-L1 and GZ-I7: real races between two processes through ChatService::openChannel(). They need a server
 * database, so they run only with one, for example:
 *
 *     CHAT_KIT_RACE_DATABASE_URL='mysql://root:pass@db:3306/race?serverVersion=8.4.0&charset=utf8mb4' vendor/bin/phpunit --filter ChatChannelRaceTest
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
        foreach (['none', 'sleep', 'locked'] as $mode) {
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

    /**
     * @return array{array{result: string, uuid: string|null, emOpen: bool}, array{result: string, uuid: string|null, emOpen: bool}}
     */
    private function race(string $anaKey, string $bobKey, string $mode): array
    {
        $start = (string) (microtime(true) + 1.5);
        $ana   = $this->start(['open', 'ana@example.test', $anaKey, $mode, $start]);
        $bob   = $this->start(['open', 'bob@example.test', $bobKey, $mode, $start]);

        return [$this->finish($ana), $this->finish($bob)];
    }

    private function rows(): int
    {
        $process = $this->start(['count']);
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
     * @return array{result: string, uuid: string|null, emOpen: bool}
     */
    private function finish(array $process): array
    {
        $output = (string) stream_get_contents($process['stdout']);
        fclose($process['stdout']);
        proc_close($process['handle']);
        /** @var array{result: string, uuid: string|null, emOpen: bool} $decoded */
        $decoded = json_decode($output, true, 4, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

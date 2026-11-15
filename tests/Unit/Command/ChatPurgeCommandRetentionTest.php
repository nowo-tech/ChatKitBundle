<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Command;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Support\ChatRetention;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingLogger;
use Nowo\ChatKitBundle\Tests\Support\Chat\ScriptedRetentionProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

use const PHP_INT_MAX;

/**
 * 1.31: the host's retention is asked on every run, in the same process, and a failing provider deletes nothing.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatPurgeCommandRetentionTest extends TestCase
{
    public function testTheValueIsReadAgainBetweenTwoRunsInOneProcess(): void
    {
        $cutoffs = [];
        $repo    = $this->createStub(ChatMessageRepository::class);
        $repo->method('purgeOlderThan')->willReturnCallback(static function (DateTimeImmutable $cutoff) use (&$cutoffs): int {
            $cutoffs[] = $cutoff->format('Y-m-d');

            return 0;
        });
        $provider = new ScriptedRetentionProvider([90, 45, null]);

        $tester = new CommandTester(new ChatPurgeCommand($repo, 365, new MockClock('2026-11-15 12:00:00'), retention: $provider));

        $tester->execute([]);
        self::assertStringContainsString('older than 90 days', $tester->getDisplay());
        $tester->execute([]);
        self::assertStringContainsString('older than 45 days', $tester->getDisplay());
        $tester->execute([]);
        self::assertStringContainsString('older than 365 days', $tester->getDisplay());
        self::assertSame(['2026-08-17', '2026-10-01', '2025-11-15'], $cutoffs);
    }

    public function testAFailingProviderStopsBeforeAnyDeletion(): void
    {
        $repo = $this->createMock(ChatMessageRepository::class);
        $repo->expects(self::never())->method('purgeOlderThan');
        $provider = new class implements RetentionPolicyProviderInterface {
            public function retentionDays(): ?int
            {
                throw new RuntimeException('settings unavailable');
            }
        };

        $this->expectException(RuntimeException::class);
        (new CommandTester(new ChatPurgeCommand($repo, 365, retention: $provider)))->execute([]);
    }

    public function testAHugeValueNeverReachesTheRepositoryWithARecentCutoff(): void
    {
        foreach ([ChatRetention::MAX_DAYS + 1, 10 ** 13, PHP_INT_MAX] as $days) {
            $cutoffs = [];
            $repo    = $this->createStub(ChatMessageRepository::class);
            $repo->method('purgeOlderThan')->willReturnCallback(static function (DateTimeImmutable $cutoff) use (&$cutoffs): int {
                $cutoffs[] = $cutoff;

                return 0;
            });
            $clock = new MockClock('2026-11-15 12:00:00');
            (new CommandTester(new ChatPurgeCommand($repo, 365, $clock, retention: new ScriptedRetentionProvider([$days]))))->execute([]);

            self::assertCount(1, $cutoffs);
            self::assertSame('1926-12-10', $cutoffs[0]->format('Y-m-d'));
        }
    }

    public function testAProviderFailureIsLoggedAndNothingIsDeleted(): void
    {
        $repo = $this->createMock(ChatMessageRepository::class);
        $repo->expects(self::never())->method('purgeOlderThan');
        $logger   = new RecordingLogger();
        $provider = new class implements RetentionPolicyProviderInterface {
            public function retentionDays(): ?int
            {
                throw new RuntimeException('settings unavailable');
            }
        };

        try {
            (new CommandTester(new ChatPurgeCommand($repo, 365, logger: $logger, retention: $provider)))->execute([]);
            self::fail('the failure is rethrown');
        } catch (RuntimeException) {
            self::assertSame(['error Chat purge failed. 0 RuntimeException settings unavailable'], $logger->lines);
        }
    }

    public function testACutoffInsideTheMinimumWindowAbortsWithoutDeleting(): void
    {
        $repo = $this->createMock(ChatMessageRepository::class);
        $repo->expects(self::never())->method('purgeOlderThan');
        $logger = new RecordingLogger();
        $clock  = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                // modify() answers "now" for the retention window: a broken date computation.
                return new class('2026-11-15 12:00:00') extends DateTimeImmutable {
                    public function modify(string $modifier): static
                    {
                        return $modifier === '-30 days' ? parent::modify($modifier) : $this;
                    }
                };
            }

            public function sleep(float|int $seconds): void
            {
            }

            public function withTimeZone(DateTimeZone|string $timezone): static
            {
                return $this;
            }
        };
        $tester = new CommandTester(new ChatPurgeCommand($repo, 365, $clock, $logger));

        self::assertSame(1, $tester->execute([]));
        self::assertCount(1, $logger->lines);
        self::assertStringStartsWith('error Chat purge aborted', $logger->lines[0]);
    }
}

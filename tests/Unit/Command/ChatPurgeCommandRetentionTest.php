<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Tests\Support\Chat\ScriptedRetentionProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

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
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Support;

use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\Support\ChatRetention;
use Nowo\ChatKitBundle\Support\NullRetentionPolicyProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingLogger;
use Nowo\ChatKitBundle\Tests\Support\Chat\ScriptedRetentionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatRetentionTest extends TestCase
{
    public function testNullProviderProvidesNothing(): void
    {
        self::assertNull((new NullRetentionPolicyProvider())->retentionDays());
    }

    /**
     * @param int|null $asked Provider answer
     * @param int $configured Configured days
     * @param int $expected Days used
     * @param int $warnings Warnings logged
     */
    #[DataProvider('cases')]
    public function testResolution(?int $asked, int $configured, int $expected, int $warnings): void
    {
        $logger    = new RecordingLogger();
        $retention = new ChatRetention($configured, new ScriptedRetentionProvider([$asked]), $logger);

        self::assertSame($expected, $retention->days());
        self::assertCount($warnings, $logger->lines);
    }

    /**
     * @return iterable<string, array{int|null, int, int, int}>
     */
    public static function cases(): iterable
    {
        yield 'provider wins' => [90, 365, 90, 0];
        yield 'null falls back' => [null, 365, 365, 0];
        yield 'below minimum is raised' => [10, 365, 30, 1];
        yield 'minimum is accepted' => [30, 365, 30, 0];
        yield 'zero is ignored' => [0, 200, 200, 1];
        yield 'negative is ignored' => [-5, 200, 200, 1];
        yield 'unusable and configured below minimum' => [0, 5, 30, 2];
        yield 'configured below minimum' => [null, 5, 30, 1];
    }

    public function testNoProviderUsesTheConfiguredDays(): void
    {
        self::assertSame(120, (new ChatRetention(120))->days());
        self::assertSame(30, (new ChatRetention(1))->days());
    }

    public function testWarningsCarryValuesOnly(): void
    {
        $logger = new RecordingLogger();
        (new ChatRetention(365, new ScriptedRetentionProvider([7]), $logger))->days();

        self::assertSame(['warning Chat retention below the minimum was raised. 7 30 provider'], $logger->lines);
    }

    public function testProviderIsAskedAgainOnEveryCall(): void
    {
        $provider  = new ScriptedRetentionProvider([60, 90, null]);
        $retention = new ChatRetention(365, $provider);

        self::assertSame(60, $retention->days());
        self::assertSame(90, $retention->days());
        self::assertSame(365, $retention->days());
        self::assertSame(3, $provider->calls);
    }

    public function testProviderFailurePropagates(): void
    {
        $provider = new class implements RetentionPolicyProviderInterface {
            public function retentionDays(): ?int
            {
                throw new RuntimeException('settings unavailable');
            }
        };

        $this->expectException(RuntimeException::class);
        (new ChatRetention(365, $provider))->days();
    }
}

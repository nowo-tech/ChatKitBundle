<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\RuntimeSetting;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\RuntimeSetting\RuntimeSettingRetentionPolicyProvider;
use Nowo\ChatKitBundle\Support\ChatRetention;
use Nowo\ChatKitBundle\Support\RetentionUnavailableException;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingLogger;
use Nowo\ChatKitBundle\Tests\Support\RuntimeSetting\FakeRuntimeValueSource;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingUnavailable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\Validator\IntRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * 1.32: the retention port answered by a runtime setting, as a DDD host wires it (its own source, the contract's
 * resolver, no Symfony bridge). Invalid or unreadable values fail closed; nothing is kept across units of work.
 */
final class RuntimeSettingRetentionPolicyProviderTest extends TestCase
{
    private const ID = 'chat.retention_days';

    private FakeRuntimeValueSource $source;

    private RuntimeSettingResolver $resolver;

    protected function setUp(): void
    {
        $this->source   = new FakeRuntimeValueSource();
        $this->resolver = new RuntimeSettingResolver($this->source, versionTtl: 0);
    }

    public function testDbThenEnvUsesTheConfiguredValueWhileNothingIsStored(): void
    {
        self::assertSame(120, $this->provider(RuntimeSettingSource::DbThenEnv, 120)->retentionDays());
    }

    public function testDbFailsWhileNothingIsStored(): void
    {
        // Audit RN-M1: never a bundle default shorter than the host's retention_days.
        try {
            $this->provider(RuntimeSettingSource::Db, 400)->retentionDays();
            self::fail('a missing db value must not fall back');
        } catch (RetentionUnavailableException $exception) {
            self::assertInstanceOf(RuntimeSettingUnavailable::class, $exception->getPrevious());
        }
    }

    public function testEnvNeverReadsTheSource(): void
    {
        $this->source->store(self::ID, '90');

        self::assertSame(120, $this->provider(RuntimeSettingSource::Env, 120)->retentionDays());
        self::assertSame(0, $this->source->reads);
    }

    public function testAStoredValueWinsAndAChangeIsSeenAtTheNextUnitOfWork(): void
    {
        $provider = $this->provider(RuntimeSettingSource::DbThenEnv, 120);
        $this->source->store(self::ID, '90');
        self::assertSame(90, $provider->retentionDays());

        $this->source->store(self::ID, '45');
        self::assertSame(90, $provider->retentionDays(), 'one value per unit of work');
        $this->resolver->clearRuntimeCache();
        self::assertSame(45, $provider->retentionDays(), 'the next unit of work reads the new value');

        $this->source->store(self::ID, null);
        $this->resolver->clearRuntimeCache();
        self::assertSame(120, $provider->retentionDays(), 'unset: back to the configured value');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStored(): iterable
    {
        yield 'below the minimum' => ['5'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-30'];
        yield 'above the maximum' => ['36501'];
        yield 'not a number' => ['ninety'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidStored')]
    public function testAnInvalidStoredValueFailsClosed(string $raw): void
    {
        $this->source->store(self::ID, $raw);

        try {
            $this->provider(RuntimeSettingSource::DbThenEnv, 120)->retentionDays();
            self::fail('an invalid stored value must not fall back');
        } catch (RetentionUnavailableException $exception) {
            self::assertSame(self::ID, $exception->setting);
            self::assertStringContainsString('"chat.retention_days"', $exception->getMessage());
            self::assertInstanceOf(InvalidRuntimeSettingValue::class, $exception->getPrevious());
        }
    }

    public function testAFailingSourceFailsClosed(): void
    {
        $this->source->failing = true;

        try {
            $this->provider(RuntimeSettingSource::DbThenEnv, 120)->retentionDays();
            self::fail('a source failure must not fall back');
        } catch (RetentionUnavailableException $exception) {
            self::assertInstanceOf(RuntimeSettingUnavailable::class, $exception->getPrevious());
        }
    }

    public function testAnInvalidConfiguredFallbackFailsClosed(): void
    {
        // %env(int:CHAT_RETENTION_DAYS)% coerced to 0, nothing stored: the 1.31.1 rule, through the runtime setting.
        $this->expectException(RetentionUnavailableException::class);
        $this->provider(RuntimeSettingSource::DbThenEnv, 0)->retentionDays();
    }

    public function testAValidStoredValueWinsOverAnInvalidConfiguredFallback(): void
    {
        $this->source->store(self::ID, '60');

        self::assertSame(60, $this->provider(RuntimeSettingSource::DbThenEnv, 0)->retentionDays());
    }

    public function testNoValueFromANonRequiredDefinitionFailsTooNeverNull(): void
    {
        // Audit RN-L1: a definition a DDD host built without `required` resolves to null: still a failure.
        $definition = new RuntimeSettingDefinition(self::ID, RuntimeSettingType::Int, RuntimeSettingSource::Db, validator: new IntRange(30, 36500));

        $this->expectException(RetentionUnavailableException::class);
        $this->expectExceptionMessage('the setting has no value');
        (new RuntimeSettingRetentionPolicyProvider($this->resolver, $definition))->retentionDays();
    }

    public function testADefinitionThatCannotBeBuiltFailsTheRunWithTheConfiguredId(): void
    {
        $provider = new RuntimeSettingRetentionPolicyProvider($this->resolver, static function (): RuntimeSettingDefinition {
            throw RuntimeSettingConfigurationError::because('The configured value of runtime setting "CHAT_RETENTION_DAYS" is invalid.');
        }, 'CHAT_RETENTION_DAYS');

        try {
            $provider->retentionDays();
            self::fail('a broken definition must not fall back');
        } catch (RetentionUnavailableException $exception) {
            self::assertSame('CHAT_RETENTION_DAYS', $exception->setting);
        }
    }

    public function testANonIntegerDefinitionIsRefused(): void
    {
        $definition = new RuntimeSettingDefinition(self::ID, RuntimeSettingType::String, RuntimeSettingSource::Env, configured: '90');

        $this->expectException(RetentionUnavailableException::class);
        (new RuntimeSettingRetentionPolicyProvider($this->resolver, $definition))->retentionDays();
    }

    public function testThePurgeDeletesNothingAndFailsOnAnInvalidRuntimeValue(): void
    {
        $repo = $this->createMock(ChatMessageRepository::class);
        $repo->expects(self::never())->method('purgeOlderThan');
        $logger = new RecordingLogger();
        $this->source->store(self::ID, '7');

        $tester = new CommandTester(new ChatPurgeCommand($repo, 120, new MockClock('2026-11-16 12:00:00'), $logger, retention: $this->provider(RuntimeSettingSource::DbThenEnv, 120)));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('chat.retention_days', $tester->getDisplay());
        self::assertCount(1, $logger->lines);
        self::assertStringStartsWith('error Chat purge aborted: the runtime retention setting is invalid or unavailable.', $logger->lines[0]);
    }

    public function testThePurgeUsesTheRuntimeValueOnEveryRun(): void
    {
        $cutoffs = [];
        $repo    = $this->createStub(ChatMessageRepository::class);
        $repo->method('purgeOlderThan')->willReturnCallback(static function (DateTimeImmutable $cutoff) use (&$cutoffs): int {
            $cutoffs[] = $cutoff->format('Y-m-d');

            return 0;
        });
        $command = new ChatPurgeCommand($repo, 120, new MockClock('2026-11-16 12:00:00'), retention: $this->provider(RuntimeSettingSource::DbThenEnv, 120));

        foreach (['90', '45', null] as $raw) {
            $this->source->store(self::ID, $raw);
            $this->resolver->clearRuntimeCache();
            self::assertSame(0, (new CommandTester($command))->execute([]));
        }

        self::assertSame(['2026-08-18', '2026-10-02', '2026-07-19'], $cutoffs);
    }

    private function provider(RuntimeSettingSource $source, int $configured): RuntimeSettingRetentionPolicyProvider
    {
        $definition = new RuntimeSettingDefinition(
            self::ID,
            RuntimeSettingType::Int,
            $source,
            validator: new IntRange(ChatRetention::MIN_DAYS, ChatRetention::MAX_DAYS),
            configured: $configured,
            required: true,
        );

        return new RuntimeSettingRetentionPolicyProvider($this->resolver, $definition);
    }
}

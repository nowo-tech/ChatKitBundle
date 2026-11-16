<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\RuntimeRetentionPass;
use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\RuntimeSetting\RuntimeSettingRetentionPolicyProvider;
use Nowo\ChatKitBundle\Support\NullRetentionPolicyProvider;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingErrorPolicy;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\Validator\IntRange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.32: `runtime.retention_days` registers a runtime setting and its adapter; without it nothing changes.
 */
final class RuntimeRetentionWiringTest extends TestCase
{
    public function testWithoutTheNodeNothingIsRegistered(): void
    {
        $container = $this->load([]);

        self::assertNull($container->getParameter(NowoChatKitExtension::RUNTIME_RETENTION_PARAMETER));
        self::assertFalse($container->hasDefinition(NowoChatKitExtension::RUNTIME_RETENTION_DEFINITION));
        self::assertFalse($container->hasDefinition(NowoChatKitExtension::RUNTIME_RETENTION_VALIDATOR));
        self::assertFalse($container->hasDefinition(RuntimeSettingRetentionPolicyProvider::class));
        self::assertSame([], $container->findTaggedServiceIds(RuntimeSettingsConfiguration::DEFINITION_TAG));

        // The pass leaves the default port alone, with or without a resolver.
        $container->register(RuntimeSettingResolver::class);
        (new RuntimeRetentionPass())->process($container);
        self::assertSame(NullRetentionPolicyProvider::class, (string) $container->getAlias(RetentionPolicyProviderInterface::class));
    }

    public function testAStringIsTheSettingIdReadDatabaseFirst(): void
    {
        $container = $this->load(['retention_days' => 120, 'runtime' => ['retention_days' => 'chat.retention_days']]);

        self::assertSame('chat.retention_days', $container->getParameter(NowoChatKitExtension::RUNTIME_RETENTION_PARAMETER));
        $definition = $container->getDefinition(NowoChatKitExtension::RUNTIME_RETENTION_DEFINITION);
        self::assertTrue($definition->hasTag(RuntimeSettingsConfiguration::DEFINITION_TAG));
        self::assertSame('chat.retention_days', $definition->getArgument('$id'));
        self::assertSame(RuntimeSettingType::Int, $definition->getArgument('$type'));
        self::assertSame(RuntimeSettingSource::DbThenEnv, $definition->getArgument('$source'));
        self::assertSame(RuntimeSettingErrorPolicy::FailClosed, $definition->getArgument('$onError'));
        self::assertSame(120, $definition->getArgument('$configured'));
        self::assertNull($definition->getArgument('$default'), 'audit RN-M1: no bundle default');
        self::assertTrue($definition->getArgument('$required'));
        self::assertSame(NowoChatKitExtension::RUNTIME_RETENTION_VALIDATOR, (string) $definition->getArgument('$validator'));

        $validator = $container->getDefinition(NowoChatKitExtension::RUNTIME_RETENTION_VALIDATOR);
        self::assertSame(IntRange::class, $validator->getClass());
        self::assertSame([30, 36500, false], $validator->getArguments());

        $adapter = $container->getDefinition(RuntimeSettingRetentionPolicyProvider::class);
        self::assertSame(RuntimeSettingResolver::class, (string) $adapter->getArgument(0));
        self::assertInstanceOf(ServiceClosureArgument::class, $adapter->getArgument(1));
        self::assertSame('chat.retention_days', $adapter->getArgument(2));
    }

    public function testTheFullFormTakesTheSourceAndAnEnvironmentName(): void
    {
        $container  = $this->load(['retention_days' => 200, 'runtime' => ['retention_days' => ['source' => 'db', 'key' => 'CHAT_RETENTION_DAYS']]]);
        $definition = $container->getDefinition(NowoChatKitExtension::RUNTIME_RETENTION_DEFINITION);

        self::assertSame('CHAT_RETENTION_DAYS', $definition->getArgument('$id'));
        self::assertSame(RuntimeSettingSource::Db, $definition->getArgument('$source'));
        self::assertSame(200, $definition->getArgument('$configured'));
    }

    public function testAnEmptyNodeUsesTheDefaults(): void
    {
        $definition = $this->load(['runtime' => ['retention_days' => []]])->getDefinition(NowoChatKitExtension::RUNTIME_RETENTION_DEFINITION);

        self::assertSame('chat.retention_days', $definition->getArgument('$id'));
        self::assertSame(RuntimeSettingSource::DbThenEnv, $definition->getArgument('$source'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidNodes(): iterable
    {
        yield 'fallback is refused (the purge deletes data)' => [['on_error' => 'fallback']];
        yield 'unknown source' => [['source' => 'redis']];
        yield 'env-prefixed id' => [['key' => 'env.CHAT_RETENTION_DAYS']];
        yield 'upper-case namespace' => [['key' => 'Chat.retention_days']];
        yield 'spaces' => [['key' => 'chat retention']];
        yield 'trailing newline' => [['key' => "chat.retention_days\n"]];
        yield 'too long' => [['key' => 'chat.' . str_repeat('a', 200)]];
        // Passes the tree's pattern (dotted segments), refused by the contract's length rule (key <= 128 bytes).
        yield 'too long over several segments' => [['key' => 'chat.a.' . str_repeat('b', 150)]];
    }

    /**
     * @param array<string, mixed> $node
     */
    #[DataProvider('invalidNodes')]
    public function testAnInvalidNodeFailsTheBuild(array $node): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['runtime' => ['retention_days' => $node]]);
    }

    public function testThePassPutsTheAdapterBehindThePort(): void
    {
        $container = $this->load(['runtime' => ['retention_days' => 'chat.retention_days']]);
        $container->register(RuntimeSettingResolver::class);

        (new RuntimeRetentionPass())->process($container);

        self::assertSame(RuntimeSettingRetentionPolicyProvider::class, (string) $container->getAlias(RetentionPolicyProviderInterface::class));
    }

    public function testThePassBindsThePortWhenNothingElseDid(): void
    {
        $container = $this->load(['runtime' => ['retention_days' => 'chat.retention_days']]);
        $container->register(RuntimeSettingResolver::class);
        $container->removeAlias(RetentionPolicyProviderInterface::class);

        (new RuntimeRetentionPass())->process($container);

        self::assertSame(RuntimeSettingRetentionPolicyProvider::class, (string) $container->getAlias(RetentionPolicyProviderInterface::class));
    }

    public function testWithoutAResolverTheBuildFailsInsteadOfKeepingTheConfiguredValue(): void
    {
        $container = $this->load(['runtime' => ['retention_days' => 'chat.retention_days']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs the runtime-setting resolver');
        (new RuntimeRetentionPass())->process($container);
    }

    public function testAnApplicationProviderAndTheRuntimeSettingCannotBothAnswer(): void
    {
        $container = $this->load(['runtime' => ['retention_days' => 'chat.retention_days']]);
        $container->register(RuntimeSettingResolver::class);
        $container->register('app.retention', NullRetentionPolicyProvider::class);
        $container->setAlias(RetentionPolicyProviderInterface::class, 'app.retention');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('both answer the retention');
        (new RuntimeRetentionPass())->process($container);
    }

    public function testAnApplicationDefinitionUnderThePortIdIsAConflictToo(): void
    {
        $container = $this->load(['runtime' => ['retention_days' => 'chat.retention_days']]);
        $container->register(RuntimeSettingResolver::class);
        $container->removeAlias(RetentionPolicyProviderInterface::class);
        $container->register(RetentionPolicyProviderInterface::class, NullRetentionPolicyProvider::class);

        $this->expectException(LogicException::class);
        (new RuntimeRetentionPass())->process($container);
    }

    public function testThePurgeCommandStillAsksThePort(): void
    {
        $container = $this->load(['runtime' => ['retention_days' => 'chat.retention_days']]);
        $retention = $container->getDefinition(ChatPurgeCommand::class)->getArgument('$retention');

        self::assertInstanceOf(Reference::class, $retention);
        self::assertSame(RetentionPolicyProviderInterface::class, (string) $retention);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function load(array $profile): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoChatKitExtension())->load([['profiles' => ['default' => ['user_class' => TestUser::class] + $profile]]], $container);

        return $container;
    }
}

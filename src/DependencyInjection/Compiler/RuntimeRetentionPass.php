<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\RuntimeSetting\RuntimeSettingRetentionPolicyProvider;
use Nowo\ChatKitBundle\Support\NullRetentionPolicyProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function in_array;
use function is_string;
use function sprintf;

/**
 * 1.32: with `runtime.retention_days`, points {@see RetentionPolicyProviderInterface} at
 * {@see RuntimeSettingRetentionPolicyProvider}. Fails the build, instead of silently keeping the configured value,
 * when the contract's resolver is missing (`NowoRuntimeSettingBundle` not registered and no resolver service of a DDD
 * host) or when the host also aliased the port to its own provider (two sources for one value: pick one).
 */
final class RuntimeRetentionPass implements CompilerPassInterface
{
    /** The contract's resolver service id (the class name; NowoRuntimeSettingBundle aliases it). */
    private const RESOLVER = 'Nowo\\RuntimeSettingContract\\Contract\\RuntimeSettingResolver';

    /**
     * @param ContainerBuilder $container The container being compiled
     *
     * @throws LogicException When the resolver is missing or the host aliased the retention port to its own provider
     */
    public function process(ContainerBuilder $container): void
    {
        $key = $container->hasParameter(NowoChatKitExtension::RUNTIME_RETENTION_PARAMETER) ? $container->getParameter(NowoChatKitExtension::RUNTIME_RETENTION_PARAMETER) : null;
        if (!is_string($key)) {
            return;
        }
        if (!$container->has(self::RESOLVER)) {
            throw new LogicException(sprintf('nowo_chat_kit: runtime.retention_days ("%s") needs the runtime-setting resolver: register Nowo\RuntimeSettingContract\Bridge\Symfony\NowoRuntimeSettingBundle (and bind its source), or define a %s service. Remove runtime.retention_days to keep the configured retention_days.', $key, self::RESOLVER));
        }

        $port    = RetentionPolicyProviderInterface::class;
        $current = match (true) {
            $container->hasAlias($port)      => (string) $container->getAlias($port),
            $container->hasDefinition($port) => $port,
            default                          => null,
        };
        if ($current !== null && !in_array($current, [NullRetentionPolicyProvider::class, RuntimeSettingRetentionPolicyProvider::class], true)) {
            throw new LogicException(sprintf('nowo_chat_kit: runtime.retention_days ("%s") and an application retention provider (%s aliased to %s) both answer the retention. Keep one: remove runtime.retention_days, or the alias.', $key, $port, $current));
        }

        $container->setAlias($port, RuntimeSettingRetentionPolicyProvider::class);
    }
}

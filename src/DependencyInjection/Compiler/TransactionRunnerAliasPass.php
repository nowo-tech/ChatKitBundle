<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.30 (Beacon spec 239, recipe R1): aliases nowo-tech/transaction-contract's {@see TransactionRunnerInterface} to
 * ChatKit's default runner ({@see ChatKitTransactionRunner}, `nowo_chat_kit.transaction_runner`) only when nothing
 * defines the interface. A host alias, or a bundle that registers its own (DoctrineDeadlockRetryBundle's retrying
 * adapter, pass priority 10), wins; ChatKit's services are wired to the interface, so they then use that runner.
 * Registered at the default priority, never in the extension's load().
 *
 * @internal
 */
final class TransactionRunnerAliasPass implements CompilerPassInterface
{
    /** ChatKit's default runner. */
    public const string SERVICE_ID = 'nowo_chat_kit.transaction_runner';

    /**
     * @param ContainerBuilder $container The container being compiled
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->has(TransactionRunnerInterface::class) || !$container->has(self::SERVICE_ID)) {
            return;
        }

        $container->setAlias(TransactionRunnerInterface::class, self::SERVICE_ID);
    }
}

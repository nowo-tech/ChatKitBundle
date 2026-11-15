<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ChatKitBundle\DependencyInjection\Compiler\TransactionRunnerAliasPass;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\TransactionContract\TransactionRunnerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.30 (recipe R1): TransactionRunnerInterface goes to ChatKit's default runner only when nothing defines it.
 */
final class TransactionRunnerAliasPassTest extends TestCase
{
    public function testTheInterfaceGoesToTheDefaultWhenAbsent(): void
    {
        $container = new ContainerBuilder();
        $container->register(TransactionRunnerAliasPass::SERVICE_ID, ChatKitTransactionRunner::class);

        (new TransactionRunnerAliasPass())->process($container);

        self::assertSame(TransactionRunnerAliasPass::SERVICE_ID, (string) $container->getAlias(TransactionRunnerInterface::class));
    }

    public function testAHostAliasWins(): void
    {
        $container = new ContainerBuilder();
        $container->register(TransactionRunnerAliasPass::SERVICE_ID, ChatKitTransactionRunner::class);
        $container->register('app.runner', ChatKitTransactionRunner::class);
        $container->setAlias(TransactionRunnerInterface::class, 'app.runner');

        (new TransactionRunnerAliasPass())->process($container);

        self::assertSame('app.runner', (string) $container->getAlias(TransactionRunnerInterface::class));
    }

    public function testAServiceUnderTheInterfaceIdWins(): void
    {
        $container = new ContainerBuilder();
        $container->register(TransactionRunnerAliasPass::SERVICE_ID, ChatKitTransactionRunner::class);
        $container->register(TransactionRunnerInterface::class, ChatKitTransactionRunner::class);

        (new TransactionRunnerAliasPass())->process($container);

        self::assertFalse($container->hasAlias(TransactionRunnerInterface::class));
    }

    public function testNothingWithoutTheDefault(): void
    {
        $container = new ContainerBuilder();

        (new TransactionRunnerAliasPass())->process($container);

        self::assertFalse($container->has(TransactionRunnerInterface::class));
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\Support\NullRetentionPolicyProvider;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.31: the purge command gets the retention provider port (null default, the host aliases it).
 */
final class NowoChatKitExtensionRetentionTest extends TestCase
{
    public function testThePurgeCommandAsksTheProviderPortAndTheDefaultProvidesNothing(): void
    {
        $container = new ContainerBuilder();
        (new NowoChatKitExtension())->load([['profiles' => ['default' => ['user_class' => TestUser::class]]]], $container);

        self::assertSame(NullRetentionPolicyProvider::class, (string) $container->getAlias(RetentionPolicyProviderInterface::class));
        $retention = $container->getDefinition(ChatPurgeCommand::class)->getArgument('$retention');
        self::assertInstanceOf(Reference::class, $retention);
        self::assertSame(RetentionPolicyProviderInterface::class, (string) $retention);
    }
}

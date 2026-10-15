<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\RealtimeTransportPass;
use Nowo\ChatKitBundle\Realtime\MercureChatRealtime;
use Nowo\ChatKitBundle\Realtime\NullChatRealtime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mercure\HubInterface;

final class RealtimeTransportPassTest extends TestCase
{
    public function testKeepsMercureWhenAHubExistsAndRealtimeIsOn(): void
    {
        $container = $this->container(true, true);
        (new RealtimeTransportPass())->process($container);

        self::assertSame(MercureChatRealtime::class, (string) $container->getAlias(ChatRealtimeInterface::class));
    }

    public function testFallsBackWithoutHubOrWhenRealtimeIsOff(): void
    {
        foreach ([[false, true], [true, false]] as [$hub, $realtime]) {
            $container = $this->container($hub, $realtime);
            (new RealtimeTransportPass())->process($container);

            self::assertSame(NullChatRealtime::class, (string) $container->getAlias(ChatRealtimeInterface::class));
        }
        self::assertFalse($container->hasDefinition(MercureChatRealtime::class) && !$hub);
    }

    public function testLeavesAnApplicationTransportAlone(): void
    {
        $container = $this->container(false, true);
        $container->setAlias(ChatRealtimeInterface::class, 'app.realtime');
        (new RealtimeTransportPass())->process($container);

        self::assertSame('app.realtime', (string) $container->getAlias(ChatRealtimeInterface::class));
        self::assertFalse($container->hasDefinition(MercureChatRealtime::class));

        $none = new ContainerBuilder();
        $none->setParameter('nowo_chat_kit.realtime', true);
        (new RealtimeTransportPass())->process($none);
        self::assertFalse($none->hasAlias(ChatRealtimeInterface::class));
    }

    private function container(bool $hub, bool $realtime): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_chat_kit.realtime', $realtime);
        $container->register(MercureChatRealtime::class);
        $container->register(NullChatRealtime::class);
        $container->setAlias(ChatRealtimeInterface::class, MercureChatRealtime::class);
        if ($hub) {
            $container->register(HubInterface::class);
        }

        return $container;
    }
}

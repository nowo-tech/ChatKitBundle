<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use Nowo\ChatKitBundle\Port\ChatRealtimePort;
use Nowo\ChatKitBundle\Realtime\MercureChatRealtime;
use Nowo\ChatKitBundle\Realtime\NullChatRealtime;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mercure\HubInterface;

/**
 * Keeps the default Mercure transport only when a hub is configured (and realtime is on); otherwise falls back
 * to the polling-only null transport. An application alias of ChatRealtimePort is left untouched.
 */
final class RealtimeTransportPass implements CompilerPassInterface
{
    /**
     * Removes the Mercure transport when no hub exists and points ChatRealtimePort at the null transport
     * when the hub is missing or realtime is disabled (unless the application aliased its own transport).
     *
     * @param ContainerBuilder $container The container being compiled
     */
    public function process(ContainerBuilder $container): void
    {
        $hubAvailable = $container->has(HubInterface::class);
        if (!$hubAvailable) {
            $container->removeDefinition(MercureChatRealtime::class);
        }
        if (!$container->hasAlias(ChatRealtimePort::class) || (string) $container->getAlias(ChatRealtimePort::class) !== MercureChatRealtime::class) {
            return;
        }
        if (!$hubAvailable || $container->getParameter('nowo_chat_kit.realtime') !== true) {
            $container->setAlias(ChatRealtimePort::class, NullChatRealtime::class);
        }
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Realtime\MercureChatRealtime;
use Nowo\ChatKitBundle\Realtime\NullChatRealtime;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mercure\HubInterface;

/**
 * Keeps the default Mercure transport only when a hub is configured (and realtime is on); otherwise falls back
 * to the polling-only null transport. An application alias of ChatRealtimeInterface is left untouched.
 */
final class RealtimeTransportPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $hubAvailable = $container->has(HubInterface::class);
        if (!$hubAvailable) {
            $container->removeDefinition(MercureChatRealtime::class);
        }
        if (!$container->hasAlias(ChatRealtimeInterface::class) || MercureChatRealtime::class !== (string) $container->getAlias(ChatRealtimeInterface::class)) {
            return;
        }
        if (!$hubAvailable || true !== $container->getParameter('nowo_chat_kit.realtime')) {
            $container->setAlias(ChatRealtimeInterface::class, NullChatRealtime::class);
        }
    }
}

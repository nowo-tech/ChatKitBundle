<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Port;

use LogicException;
use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeTransportInterface;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\ChatKitPortsPass;

use function get_debug_type;
use function sprintf;

/**
 * 1.27 (REQ-ARCH-001 rules 1 and 3): wraps an application's port service in the gate the bundle asks it through. Since
 * 2.0 the framework-free twins (ChatChannelSourceInterface, ChatRealtimeTransportInterface,
 * ChatParticipantResolverInterface) are the only host interfaces of these ports:
 *
 * - the service implements the twin: it is wrapped in the twin's gate (stateless);
 * - anything else: a `LogicException` (fail closed). The build already fails for a class known before compilation
 *   ({@see ChatKitPortsPass}); these factories are what the container calls for a service whose class it cannot know
 *   (a factory service).
 *
 * Stateless.
 *
 * @internal
 */
final class Ports
{
    /**
     * The channel provider the bundle asks.
     *
     * @param object $service The application's ChatChannelSourceInterface service
     *
     * @throws LogicException When the service does not implement ChatChannelSourceInterface
     *
     * @return ChatChannelPort The service's gate
     */
    public static function channelProvider(object $service): ChatChannelPort
    {
        return $service instanceof ChatChannelSourceInterface ? new ChatChannelSourceGate($service) : throw self::notTwin($service, ChatChannelSourceInterface::class);
    }

    /**
     * The realtime transport the bundle asks.
     *
     * @param object $service The application's ChatRealtimeTransportInterface service
     *
     * @throws LogicException When the service does not implement ChatRealtimeTransportInterface
     *
     * @return ChatRealtimePort The service's gate
     */
    public static function realtime(object $service): ChatRealtimePort
    {
        return $service instanceof ChatRealtimeTransportInterface ? new ChatRealtimeTransportGate($service) : throw self::notTwin($service, ChatRealtimeTransportInterface::class);
    }

    /**
     * The current-user resolver the bundle asks.
     *
     * @param object $service The application's ChatParticipantResolverInterface service
     *
     * @throws LogicException When the service does not implement ChatParticipantResolverInterface
     *
     * @return ChatCurrentUserPort The service's gate
     */
    public static function currentUser(object $service): ChatCurrentUserPort
    {
        return $service instanceof ChatParticipantResolverInterface ? new ChatParticipantResolverGate($service) : throw self::notTwin($service, ChatParticipantResolverInterface::class);
    }

    /**
     * The object the optional companion interfaces are read on: the host's twin behind a gate, else the port itself.
     * ChatAccess and ChatMentions ask the write and mention policies (ChatChannelWritePolicyInterface,
     * ChatChannelMentionPolicyInterface) on it.
     *
     * @param object $port A port as the bundle holds it
     *
     * @return object The object implementing the host's companions, if any
     */
    public static function companion(object $port): object
    {
        return $port instanceof ChatChannelSourceGate ? $port->source : $port;
    }

    private static function notTwin(object $service, string $twin): LogicException
    {
        return new LogicException(sprintf('nowo_chat_kit: the service of %1$s (%2$s) must implement "%1$s" (since 2.0 the only interface of this port; see UPGRADING.md, "Removed in 2.0").', $twin, get_debug_type($service)));
    }
}

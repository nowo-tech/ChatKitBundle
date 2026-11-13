<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Port;

use LogicException;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeTransportInterface;
use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\ChatKitPortsPass;

use function get_debug_type;
use function sprintf;

/**
 * 1.27 (REQ-ARCH-001 rules 1 and 3): picks the interface the bundle asks a host service through, per object:
 *
 * - the service implements the deprecated interface: it is returned as is and asked exactly as in 1.26 (also when it
 *   implements the 1.27 twin too, until 2.0);
 * - it implements only the 1.27 twin: it is wrapped in the twin's gate (stateless);
 * - neither: a `LogicException` (fail closed). The build already fails for a class known before compilation
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
     * @param object $service The configured service
     *
     * @return ChatChannelProviderInterface The service or its gate
     */
    public static function channelProvider(object $service): ChatChannelProviderInterface
    {
        return match (true) {
            $service instanceof ChatChannelProviderInterface => $service,
            $service instanceof ChatChannelSourceInterface   => new ChatChannelSourceGate($service),
            default                                          => throw self::neither($service, ChatChannelSourceInterface::class, ChatChannelProviderInterface::class),
        };
    }

    /**
     * The realtime transport the bundle asks.
     *
     * @param object $service The configured service
     *
     * @return ChatRealtimeInterface The service or its gate
     */
    public static function realtime(object $service): ChatRealtimeInterface
    {
        return match (true) {
            $service instanceof ChatRealtimeInterface          => $service,
            $service instanceof ChatRealtimeTransportInterface => new ChatRealtimeTransportGate($service),
            default                                            => throw self::neither($service, ChatRealtimeTransportInterface::class, ChatRealtimeInterface::class),
        };
    }

    /**
     * The current-user resolver the bundle asks.
     *
     * @param object $service The configured service
     *
     * @return CurrentChatUserResolverInterface The service or its gate
     */
    public static function currentUser(object $service): CurrentChatUserResolverInterface
    {
        return match (true) {
            $service instanceof CurrentChatUserResolverInterface => $service,
            $service instanceof ChatParticipantResolverInterface => new ChatParticipantResolverGate($service),
            default                                              => throw self::neither($service, ChatParticipantResolverInterface::class, CurrentChatUserResolverInterface::class),
        };
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

    private static function neither(object $service, string $twin, string $legacy): LogicException
    {
        return new LogicException(sprintf('nowo_chat_kit: the service of %s (%s) must implement "%s" (or the deprecated "%s").', $legacy, get_debug_type($service), $twin, $legacy));
    }
}

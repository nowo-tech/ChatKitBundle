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
 *   implements the 1.27 twin too, until 2.0); 1.39: with a deprecation;
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
            $service instanceof ChatChannelProviderInterface => self::legacy($service, ChatChannelProviderInterface::class, ChatChannelSourceInterface::class),
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
            $service instanceof ChatRealtimeInterface          => self::legacy($service, ChatRealtimeInterface::class, ChatRealtimeTransportInterface::class),
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
            $service instanceof CurrentChatUserResolverInterface => self::legacy($service, CurrentChatUserResolverInterface::class, ChatParticipantResolverInterface::class),
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

    /**
     * 1.39: the deprecation of an application port on the deprecated interface (removed in 2.0).
     *
     * @param string $class The application's class
     * @param string $legacy The deprecated interface
     * @param string $twin The interface to implement instead
     */
    public static function legacyPortMessage(string $class, string $legacy, string $twin): string
    {
        return sprintf('%1$s implements the deprecated %2$s, which is removed in 2.0. Implement %3$s instead and alias it ("%3$s: \'@%1$s\'" in config/services.yaml); see UPGRADING.md, "Framework-free port twins".', $class, $legacy, $twin);
    }

    /**
     * A service on the deprecated interface, asked as in 1.26; 1.39: with a deprecation (once per container, the
     * factory runs once per shared service).
     *
     * @template T of object
     *
     * @param T $service The application's service
     *
     * @return T The same service
     */
    private static function legacy(object $service, string $legacy, string $twin): object
    {
        trigger_deprecation('nowo-tech/chat-kit-bundle', '1.39', self::legacyPortMessage(get_debug_type($service), $legacy, $twin));

        return $service;
    }

    private static function neither(object $service, string $twin, string $legacy): LogicException
    {
        return new LogicException(sprintf('nowo_chat_kit: the service of %s (%s) must implement "%s" (or the deprecated "%s").', $legacy, get_debug_type($service), $twin, $legacy));
    }
}

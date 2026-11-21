<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeTransportInterface;
use Nowo\ChatKitBundle\Port\ChatChannelPort;
use Nowo\ChatKitBundle\Port\ChatCurrentUserPort;
use Nowo\ChatKitBundle\Port\ChatRealtimePort;
use Nowo\ChatKitBundle\Port\Ports;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function is_a;
use function is_string;
use function sprintf;

/**
 * 1.27 (REQ-ARCH-001 rules 1 and 3): puts an application's framework-free port twin behind the bundle's internal port.
 *
 * The bundle asks each port through an internal id ({@see ChatChannelPort}, {@see ChatRealtimePort},
 * {@see ChatCurrentUserPort}), which points at the bundle's default (no channels, Mercure or the null transport, the
 * security user itself). The application aliases the twin's id (ChatChannelSourceInterface,
 * ChatRealtimeTransportInterface, ChatParticipantResolverInterface) to its service:
 *
 * - the service implements the twin: the internal id becomes the twin's gate over it ({@see Ports});
 * - the class is not known before compilation (a factory service): the same factory, which decides per object and
 *   fails closed (`LogicException`) when the object does not implement the twin;
 * - it does not implement the twin: the build fails.
 *
 * 2.0: the 1.x interfaces of these ports (ChatChannelProviderInterface, ChatRealtimeInterface,
 * CurrentChatUserResolverInterface) are gone; a service still configured on one of them fails the build in
 * {@see RemovedPortsPass}, which runs first.
 *
 * Runs after RealtimeTransportPass (which may point the realtime port at the null transport).
 *
 * @internal
 */
final class ChatKitPortsPass implements CompilerPassInterface
{
    /**
     * Internal port id => the application's interface and the {@see Ports} factory of its gate.
     *
     * @var array<class-string, array{twin: class-string, factory: string}>
     */
    public const array PORTS = [
        ChatChannelPort::class     => ['twin' => ChatChannelSourceInterface::class, 'factory' => 'channelProvider'],
        ChatRealtimePort::class    => ['twin' => ChatRealtimeTransportInterface::class, 'factory' => 'realtime'],
        ChatCurrentUserPort::class => ['twin' => ChatParticipantResolverInterface::class, 'factory' => 'currentUser'],
    ];

    /**
     * @param ContainerBuilder $container The container being compiled
     *
     * @throws LogicException when the service of a twin does not implement it
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::PORTS as $internal => $port) {
            $twin = $port['twin'];
            if (!$container->hasAlias($twin) && !$container->hasDefinition($twin)) {
                continue;
            }
            $target = $this->resolve($container, $twin);
            $class  = $this->classOf($container, $target);
            if ($class !== null && !is_a($class, $twin, true)) {
                throw new LogicException(sprintf('nowo_chat_kit: the service "%s" (%s) of %s must implement it.', $target, $class, $twin));
            }

            $public = $this->isPublic($container, $internal);
            if ($container->hasAlias($internal)) {
                $container->removeAlias($internal);
            }
            $container->setDefinition($internal, (new Definition($internal))
                ->setFactory([Ports::class, $port['factory']])
                ->setArguments([new Reference($twin)])
                ->setPublic($public));
        }
    }

    private function isPublic(ContainerBuilder $container, string $id): bool
    {
        return match (true) {
            $container->hasAlias($id)      => $container->getAlias($id)->isPublic(),
            $container->hasDefinition($id) => $container->getDefinition($id)->isPublic(),
            default                        => false,
        };
    }

    private function resolve(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }

    /**
     * @return class-string|null null when the class cannot be known before the container is compiled
     */
    private function classOf(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->hasDefinition($id)) {
            return null;
        }
        $definition = $container->getDefinition($id);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return is_string($class) && class_exists($class) ? $class : null;
    }
}

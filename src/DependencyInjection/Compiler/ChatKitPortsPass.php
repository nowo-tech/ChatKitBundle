<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ChatKitBundle\Channel\NullChannelProvider;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeTransportInterface;
use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Port\Ports;
use Nowo\ChatKitBundle\Realtime\MercureChatRealtime;
use Nowo\ChatKitBundle\Realtime\NullChatRealtime;
use Nowo\ChatKitBundle\Security\SecurityChatUserResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function in_array;
use function is_a;
use function is_string;
use function sprintf;

/**
 * 1.27 (REQ-ARCH-001 rules 1 and 3): wires the framework-free twins of the three host ports typed with HttpFoundation or
 * Security types, and refuses a port service that implements neither interface.
 *
 * The bundle asks each port through the deprecated interface's service id (ChatChannelProviderInterface,
 * ChatRealtimeInterface, CurrentChatUserResolverInterface). The application aliases either that id or the twin's id
 * (ChatChannelSourceInterface, ChatRealtimeTransportInterface, ChatParticipantResolverInterface) to its service:
 *
 * - an alias of the twin id is used when the deprecated id still points at the bundle's default (an explicit alias of
 *   the deprecated id wins);
 * - the service implements the deprecated interface: nothing changes (asked exactly as in 1.26, also when it implements
 *   the twin too);
 * - it implements only the twin: the deprecated id becomes the twin's internal gate over it ({@see Ports});
 * - the class is not known before compilation (a factory service): the id becomes the {@see Ports} factory, which
 *   decides per object and fails closed (`LogicException`) when the object implements neither;
 * - it implements neither: the build fails.
 *
 * Runs after RealtimeTransportPass (which may point ChatRealtimeInterface at the null transport).
 *
 * @internal
 */
final class ChatKitPortsPass implements CompilerPassInterface
{
    /**
     * @var array<string, array{twin: class-string, factory: string, defaults: list<class-string>}>
     */
    public const array PORTS = [
        ChatChannelProviderInterface::class     => ['twin' => ChatChannelSourceInterface::class, 'factory' => 'channelProvider', 'defaults' => [NullChannelProvider::class]],
        ChatRealtimeInterface::class            => ['twin' => ChatRealtimeTransportInterface::class, 'factory' => 'realtime', 'defaults' => [MercureChatRealtime::class, NullChatRealtime::class]],
        CurrentChatUserResolverInterface::class => ['twin' => ChatParticipantResolverInterface::class, 'factory' => 'currentUser', 'defaults' => [SecurityChatUserResolver::class]],
    ];

    /**
     * @param ContainerBuilder $container The container being compiled
     *
     * @throws LogicException when a port service implements neither interface
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::PORTS as $legacy => $port) {
            if (!$container->hasAlias($legacy) && !$container->hasDefinition($legacy)) {
                continue;
            }
            $target = $this->resolve($container, $legacy);
            if (in_array($target, $port['defaults'], true) && ($container->hasAlias($port['twin']) || $container->hasDefinition($port['twin']))) {
                $twinTarget = $this->resolve($container, $port['twin']);
                if (!in_array($twinTarget, $port['defaults'], true)) {
                    $target = $twinTarget;
                }
            }

            $class = $this->classOf($container, $target);
            if ($class !== null && is_a($class, $legacy, true)) {
                if ($target !== $this->resolve($container, $legacy)) {
                    $container->setAlias($legacy, $target)->setPublic($this->isPublic($container, $legacy));
                }

                continue; // Asked through the deprecated interface, exactly as in 1.26.
            }
            if ($class !== null && !is_a($class, $port['twin'], true)) {
                throw new LogicException(sprintf('nowo_chat_kit: the service "%s" (%s) of %s must implement "%s" (or the deprecated "%s").', $target, $class, $legacy, $port['twin'], $legacy));
            }

            $public = $this->isPublic($container, $legacy);
            if ($target === $legacy) {
                // The service is defined under the port id itself: keep it under an inner id.
                $container->setDefinition($legacy . '.inner', $container->getDefinition($legacy));
                $container->removeDefinition($legacy);
                $target = $legacy . '.inner';
            }
            if ($container->hasAlias($legacy)) {
                $container->removeAlias($legacy);
            }
            $container->setDefinition($legacy, (new Definition($legacy))
                ->setFactory([Ports::class, $port['factory']])
                ->setArguments([new Reference($target)])
                ->setPublic($public));
        }
    }

    private function isPublic(ContainerBuilder $container, string $id): bool
    {
        return $container->hasAlias($id) ? $container->getAlias($id)->isPublic() : $container->getDefinition($id)->isPublic();
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

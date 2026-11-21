<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface;
use Nowo\ChatKitBundle\Contract\ChatParticipantResolverInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeTransportInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Throwable;

use function class_exists;
use function interface_exists;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * 2.0 (REQ-ARCH-001 rule 3, audit lesson XR-WF-M1): fails the container build on every service that still uses one of
 * the port interfaces deprecated in 1.27 and removed in 2.0 (ChatChannelProviderInterface, ChatRealtimeInterface,
 * CurrentChatUserResolverInterface), instead of letting Symfony drop it silently. A host channel source, realtime
 * transport or participant resolver that disappeared would leave the bundle on its default (no channels, no realtime,
 * no chat for a wrapped security user) while the build succeeds.
 *
 * It refuses:
 * - a service or alias under a removed id (`ChatChannelProviderInterface: '@App\Chat\Channels'`, `#[AsAlias]` of it);
 * - a decorator of a removed id (`#[AsDecorator]`, `decorates:`), `on_invalid: ignore` included;
 * - a definition whose class cannot be loaded because it implements a removed interface: Symfony records
 *   `Class "<removed interface>" not found while loading "<class>"` on an autodiscovered class and would otherwise
 *   remove it unused. Only the exact name of a removed interface matches; an unrelated missing class keeps Symfony's
 *   own lazy error;
 * - the service behind a twin's id whose class cannot be loaded for the same reason.
 *
 * Runs before optimization at a high priority, while the loader errors are still on the definitions and before any
 * pass removes a definition.
 *
 * @internal
 */
final class RemovedPortsPass implements CompilerPassInterface
{
    /**
     * Removed interface => the interface to implement instead (the 1.27 twin).
     *
     * @var array<string, class-string>
     */
    public const array REMOVED = [
        'Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface'     => ChatChannelSourceInterface::class,
        'Nowo\ChatKitBundle\Contract\ChatRealtimeInterface'            => ChatRealtimeTransportInterface::class,
        'Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface' => ChatParticipantResolverInterface::class,
    ];

    /**
     * The build error for a service on a removed port interface.
     *
     * @param string $service The service id (or class)
     * @param string $removed The removed interface
     *
     * @return string The message
     */
    public static function message(string $service, string $removed): string
    {
        $twin = self::REMOVED[$removed];

        return sprintf('nowo_chat_kit: the service "%1$s" uses %2$s, which was deprecated in 1.27 and removed in 2.0. Implement %3$s instead and alias it ("%3$s: \'@%1$s\'" in config/services.yaml); see UPGRADING.md, "To 2.0.0", "Port interfaces".', $service, $removed, $twin);
    }

    /**
     * @param ContainerBuilder $container The container being compiled
     *
     * @throws LogicException When a service still uses a removed port interface
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::REMOVED as $removed => $twin) {
            if ($container->hasAlias($removed) || $container->hasDefinition($removed)) {
                throw new LogicException(self::message($container->hasAlias($removed) ? (string) $container->getAlias($removed) : $removed, $removed));
            }
        }
        foreach ($container->getDefinitions() as $id => $definition) {
            $decorated = $definition->getDecoratedService();
            if ($decorated !== null && isset(self::REMOVED[$decorated[0]])) {
                throw new LogicException(self::message($id, $decorated[0]));
            }
            foreach ($definition->getErrors() as $error) {
                if (($removed = $this->named($error)) !== null) {
                    throw new LogicException(self::message($id, $removed));
                }
            }
        }
        foreach (self::REMOVED as $twin) {
            $class = $this->classBehind($container, $twin);
            if ($class !== null && ($error = $this->loadError($class)) !== null && ($named = $this->named($error)) !== null) {
                throw new LogicException(self::message($class, $named));
            }
        }
    }

    /**
     * The removed interface an error message names exactly (quoted, as Symfony and PHP quote class names), or null.
     */
    private function named(string $error): ?string
    {
        foreach (self::REMOVED as $removed => $twin) {
            if (str_contains($error, '"' . $removed . '"')) {
                return $removed;
            }
        }

        return null;
    }

    /**
     * Why the class cannot be loaded, or null when it can (or is unknown to the autoloader).
     */
    private function loadError(string $class): ?string
    {
        try {
            if (!class_exists($class)) {
                interface_exists($class);
            }
        } catch (Throwable $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    /**
     * The class of the service a twin's id points at, when the definition names one.
     */
    private function classBehind(ContainerBuilder $container, string $id): ?string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }
        if (!$container->hasDefinition($id)) {
            return null;
        }
        $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);

        return is_string($class) ? $class : null;
    }
}

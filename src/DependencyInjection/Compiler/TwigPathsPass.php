<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;
use function is_string;

/**
 * Registers the `@NowoChatKitBundle` Twig namespace; `templates/bundles/NowoChatKitBundle/` in the application
 * is prepended so overrides win (REQ-TWIG-001 / REQ-TWIG-002); `@!NowoChatKitBundle` always points at the bundle's own
 * templates, so an override can extend the original.
 */
final class TwigPathsPass implements CompilerPassInterface
{
    public const string TWIG_NAMESPACE = 'NowoChatKitBundle';

    public function process(ContainerBuilder $container): void
    {
        $loaderId = $this->loaderId($container);
        if ($loaderId === null) {
            return;
        }
        $definition = $container->getDefinition($loaderId);

        $projectDir = $container->hasParameter('kernel.project_dir') ? $container->getParameter('kernel.project_dir') : null;
        if (is_string($projectDir) && is_dir($override = rtrim($projectDir, '/\\') . '/templates/bundles/' . self::TWIG_NAMESPACE)) {
            // @igor-ignore - Compile-time DI definition wiring; runs once at container build
            $definition->addMethodCall('prependPath', [$override, self::TWIG_NAMESPACE]);
        }
        $views = dirname(__DIR__, 2) . '/Resources/views';
        // @igor-ignore - Compile-time DI definition wiring; runs once at container build
        $definition->addMethodCall('addPath', [$views, self::TWIG_NAMESPACE]);
        // `@!NowoChatKitBundle/…`: the original template, for overrides that extend it.
        // @igor-ignore - Compile-time DI definition wiring; runs once at container build
        $definition->addMethodCall('addPath', [$views, '!' . self::TWIG_NAMESPACE]);
    }

    private function loaderId(ContainerBuilder $container): ?string
    {
        foreach (['twig.loader.native_filesystem', 'twig.loader.filesystem'] as $id) {
            if ($container->hasDefinition($id)) {
                return $id;
            }
        }

        return null;
    }
}

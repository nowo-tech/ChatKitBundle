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

    /**
     * Adds the application override directory (prepended), the bundle views and the `!` namespace to the Twig
     * filesystem loader; does nothing when Twig is not installed.
     *
     * @param ContainerBuilder $container The container being compiled
     */
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

    /**
     * Native Twig filesystem loader, in the canonical order (REQ-TWIG-001-register): the definition behind the
     * `twig.loader.native` alias (alias chains followed), then a `twig.loader.native` definition, then
     * `twig.loader.native_filesystem` (TwigBundle's own).
     */
    private function loaderId(ContainerBuilder $container): ?string
    {
        if ($container->hasAlias('twig.loader.native')) {
            $id = (string) $container->getAlias('twig.loader.native');
            for ($hops = 0; $hops < 32 && $container->hasAlias($id); ++$hops) {
                $id = (string) $container->getAlias($id);
            }
            if ($container->hasDefinition($id)) {
                return $id;
            }
        }
        foreach (['twig.loader.native', 'twig.loader.native_filesystem'] as $id) {
            if ($container->hasDefinition($id)) {
                return $id;
            }
        }

        return null;
    }
}

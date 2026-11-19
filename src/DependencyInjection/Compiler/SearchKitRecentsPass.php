<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection\Compiler;

use Nowo\ChatKitBundle\SearchKit\ChatMessageSearchProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;

/**
 * 1.37 (audit VA-3): keeps the SearchKit provider of message search only when SearchKit cannot keep message text in the
 * browser: recents off (`nowo_search_kit.recent.enabled: false`), or recents that store references only and are checked
 * again on every display (`recent.revalidate: true`). With SearchKit's default (`revalidate: false`) a picked row's title
 * and subtitle — a message excerpt — would stay in the viewer's localStorage and show again after the message was
 * deleted or erased, or access was lost. Without SearchKit's parameters (its bundle is not registered) the provider
 * could not run either. Fail closed: the provider is removed in both cases.
 */
final class SearchKitRecentsPass implements CompilerPassInterface
{
    /** SearchKit's parameter: recents on and revalidated. */
    public const string REVALIDATE_PARAMETER = 'nowo_search_kit.recent.revalidate';

    /** SearchKit's parameter holding its settings (`recent.enabled` among them). */
    public const string SETTINGS_PARAMETER = 'nowo_search_kit.settings';

    /**
     * @param ContainerBuilder $container The container being compiled
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(ChatMessageSearchProvider::class) && !self::safe($container)) {
            $container->removeDefinition(ChatMessageSearchProvider::class);
            // Audit VA2-4: say why the chat group is missing from the palette (container build log, debug:container).
            $container->log($this, 'ChatKit: the SearchKit provider of message search was removed: SearchKit is not registered, or its recents are not revalidated (set nowo_search_kit.recent.revalidate: true, or recent.enabled: false).');
        }
    }

    /**
     * Whether SearchKit keeps no message text in the browser.
     *
     * @param ContainerBuilder $container The container being compiled
     *
     * @return bool True with recents revalidated or off
     */
    public static function safe(ContainerBuilder $container): bool
    {
        if (!$container->hasParameter(self::REVALIDATE_PARAMETER)) {
            return false;
        }
        if ($container->getParameter(self::REVALIDATE_PARAMETER) === true) {
            return true;
        }
        $settings = $container->hasParameter(self::SETTINGS_PARAMETER) ? $container->getParameter(self::SETTINGS_PARAMETER) : null;

        return is_array($settings) && is_array($settings['recent'] ?? null) && ($settings['recent']['enabled'] ?? true) === false;
    }
}

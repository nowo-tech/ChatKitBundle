<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

use function is_array;
use function sprintf;

/**
 * Loads `nowo_chat_kit` configuration and registers the bundle asset package.
 *
 * Container parameters:
 * - `nowo_chat_kit.default_profile` (string)
 * - `nowo_chat_kit.profiles` (array<string, array<string, mixed>>)
 * - `nowo_chat_kit.{user_class,retention_days,realtime,push,push_url,push_icon,push_preview,cache_pool,reactions,presence_ttl,limits}`
 *   (default profile)
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoChatKitExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Public path created by `assets:install` for this bundle (REQ-ASSETS-004).
     */
    public const ASSET_BASE_PATH = '/bundles/nowochatkit';

    /**
     * Registers the Doctrine mapping, maps ChatUserInterface to the configured user class and registers the
     * `nowo_chat_kit` asset package.
     *
     * @param ContainerBuilder $container The container being built
     */
    public function prepend(ContainerBuilder $container): void
    {
        /** @var array{default_profile: string, profiles: array<string, array{user_class: ?string, limits: array<string, int>}>} $config */
        $config  = (new Processor())->processConfiguration(new Configuration(), $this->prependableConfigs($container));
        $profile = $config['profiles'][$config['default_profile']];
        $this->prependDoctrine($container, $config['default_profile'], $profile['user_class']);

        if (!$container->hasExtension('framework')) {
            return;
        }

        $limits  = $profile['limits'];
        $limiter = static fn (int $limit, string $interval): array => ['policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval];
        $container->prependExtensionConfig('framework', [
            'rate_limiter' => [
                'nowo_chat_send'        => $limiter($limits['send_per_minute'], '1 minute'),
                'nowo_chat_action'      => $limiter($limits['actions_per_minute'], '1 minute'),
                'nowo_chat_typing'      => $limiter($limits['typing_per_minute'], '1 minute'),
                'nowo_chat_read'        => $limiter($limits['reads_per_minute'], '1 minute'),
                'nowo_chat_buzz'        => $limiter(1, $limits['buzz_interval_seconds'] . ' seconds'),
                'nowo_chat_buzz_global' => $limiter($limits['buzz_per_minute'], '1 minute'),
            ],
        ]);

        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    Configuration::ALIAS => [
                        'base_path' => self::ASSET_BASE_PATH,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Processes the configuration and loads `Resources/config/services.php`.
     *
     * @param array<array<string, mixed>> $configs Raw `nowo_chat_kit` configs
     * @param ContainerBuilder $container The container being built
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        // No configuration at all still yields one defaulted profile (normalization only runs on given input).
        $config = $this->processConfiguration(new Configuration(), $configs === [] ? [[]] : $configs);

        $container->setParameter(Configuration::ALIAS . '.default_profile', $config['default_profile']);
        $container->setParameter(Configuration::ALIAS . '.profiles', $config['profiles']);

        $profile          = $config['profiles'][$config['default_profile']];
        $profile['icons'] = array_replace(Configuration::DEFAULT_ICONS, $profile['icons']);
        foreach (['user_class', 'retention_days', 'realtime', 'push', 'push_url', 'push_icon', 'push_preview', 'cache_pool', 'page_layout', 'web_ui', 'reactions', 'icon_set', 'icons', 'presence_ttl', 'limits', 'digest', 'doctrine'] as $key) {
            $container->setParameter(Configuration::ALIAS . '.' . $key, $profile[$key]);
        }

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');
        foreach ($profile['digest'] as $key => $value) {
            $container->setParameter(Configuration::ALIAS . '.digest.' . $key, $value);
        }
        $container->setParameter(Configuration::ALIAS . '.doctrine.table_prefix', $profile['doctrine']['table_prefix']);
        $container->setAlias('nowo_chat_kit.cache', $profile['cache_pool']);
        // REQ-UI-003: the 1.x default layout means "no host layout" (the UiKit layout applies).
        $legacyLayout = $profile['page_layout'] === Configuration::LEGACY_PAGE_LAYOUT ? null : $profile['page_layout'];
        $container->setParameter(Configuration::ALIAS . '.page_layout', $legacyLayout);
        $container->setParameter(Configuration::ALIAS . '.web_ui.layout_template', $profile['web_ui']['layout_template']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.templates.page', $profile['web_ui']['templates']['page']);
    }

    /**
     * The raw configs with only what prepend() needs (user class, limits): other keys may hold %env()% values,
     * which are resolved later, in load(), never at prepend time.
     *
     * @return list<array<string, mixed>>
     */
    private function prependableConfigs(ContainerBuilder $container): array
    {
        $configs = [];
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            foreach ($config['profiles'] ?? [] as $name => $profile) {
                $config['profiles'][$name] = array_intersect_key(is_array($profile) ? $profile : [], ['user_class' => true, 'limits' => true]);
            }
            $configs[] = $config;
        }

        return $configs === [] ? [[]] : $configs;
    }

    /**
     * Entity mapping plus `resolve_target_entities` (ChatUserInterface → default profile `user_class`).
     */
    private function prependDoctrine(ContainerBuilder $container, string $profileName, ?string $userClass): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }
        if ($userClass === null || $userClass === '') {
            throw new InvalidArgumentException(sprintf('The "nowo_chat_kit.profiles.%s.user_class" configuration value is required.', $profileName));
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'resolve_target_entities' => [ChatUserInterface::class => $userClass],
                'mappings'                => [
                    'NowoChatKitBundle' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => __DIR__ . '/../Entity',
                        'prefix'    => 'Nowo\\ChatKitBundle\\Entity',
                        'alias'     => 'NowoChatKit',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Returns the configuration alias (`nowo_chat_kit`).
     *
     * @return string The alias
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}

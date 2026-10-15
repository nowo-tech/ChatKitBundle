<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection;

use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Loads `nowo_chat_kit` configuration and registers the bundle asset package.
 *
 * Container parameters:
 * - `nowo_chat_kit.default_profile` (string)
 * - `nowo_chat_kit.profiles` (array<string, array<string, mixed>>)
 * - `nowo_chat_kit.{user_class,retention_days,realtime,push,push_url,push_icon,cache_pool,reactions,presence_ttl,limits}`
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
     */
    public function prepend(ContainerBuilder $container): void
    {
        /** @var array{default_profile: string, profiles: array<string, array{user_class: ?string, limits: array<string, int>}>} $config */
        $config = (new Processor())->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS) ?: [[]]);
        $profile = $config['profiles'][$config['default_profile']];
        $this->prependDoctrine($container, $config['default_profile'], $profile['user_class']);

        if (!$container->hasExtension('framework')) {
            return;
        }

        $limits = $profile['limits'];
        $limiter = static fn (int $limit, string $interval): array => ['policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval];
        $container->prependExtensionConfig('framework', [
            'rate_limiter' => [
                'nowo_chat_send' => $limiter($limits['send_per_minute'], '1 minute'),
                'nowo_chat_action' => $limiter($limits['actions_per_minute'], '1 minute'),
                'nowo_chat_typing' => $limiter($limits['typing_per_minute'], '1 minute'),
                'nowo_chat_read' => $limiter($limits['reads_per_minute'], '1 minute'),
                'nowo_chat_buzz' => $limiter(1, $limits['buzz_interval_seconds'].' seconds'),
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
     * @param array<array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        // No configuration at all still yields one defaulted profile (normalization only runs on given input).
        $config = $this->processConfiguration(new Configuration(), [] === $configs ? [[]] : $configs);

        $container->setParameter(Configuration::ALIAS.'.default_profile', $config['default_profile']);
        $container->setParameter(Configuration::ALIAS.'.profiles', $config['profiles']);

        $profile = $config['profiles'][$config['default_profile']];
        $profile['icons'] = array_replace(Configuration::DEFAULT_ICONS, $profile['icons']);
        foreach (['user_class', 'retention_days', 'realtime', 'push', 'push_url', 'push_icon', 'cache_pool', 'reactions', 'icon_set', 'icons', 'presence_ttl', 'limits'] as $key) {
            $container->setParameter(Configuration::ALIAS.'.'.$key, $profile[$key]);
        }

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');
        $container->setAlias('nowo_chat_kit.cache', $profile['cache_pool']);
    }

    /**
     * Entity mapping plus `resolve_target_entities` (ChatUserInterface → default profile `user_class`).
     */
    private function prependDoctrine(ContainerBuilder $container, string $profileName, ?string $userClass): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }
        if (null === $userClass || '' === $userClass) {
            throw new \InvalidArgumentException(\sprintf('The "nowo_chat_kit.profiles.%s.user_class" configuration value is required.', $profileName));
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'resolve_target_entities' => [ChatUserInterface::class => $userClass],
                'mappings' => [
                    'NowoChatKitBundle' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__.'/../Entity',
                        'prefix' => 'Nowo\\ChatKitBundle\\Entity',
                        'alias' => 'NowoChatKit',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Returns the configuration alias (`nowo_chat_kit`).
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}

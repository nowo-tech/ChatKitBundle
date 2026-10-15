<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection;

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
 * - `nowo_chat_kit.profiles` (array<string, array{enabled: bool}>)
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
     * Registers the `nowo_chat_kit` asset package when FrameworkBundle is present.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('framework')) {
            return;
        }

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
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter(Configuration::ALIAS.'.default_profile', $config['default_profile']);
        $container->setParameter(Configuration::ALIAS.'.profiles', $config['profiles']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.php');
    }

    /**
     * Returns the configuration alias (`nowo_chat_kit`).
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}

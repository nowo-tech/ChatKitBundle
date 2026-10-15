<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support;

use Nowo\ChatKitBundle\NowoChatKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Minimal kernel used to boot the bundle in integration tests.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TestKernel extends Kernel
{
    public const ASSET_PACKAGES_ALIAS = 'nowo_chat_kit_test.assets_packages';

    /**
     * @param array<string, mixed> $chatKitConfig
     */
    public function __construct(private readonly array $chatKitConfig = [])
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new NowoChatKitBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $chatKitConfig = $this->chatKitConfig;
        $loader->load(static function (ContainerBuilder $container) use ($chatKitConfig): void {
            $container->loadFromExtension('framework', [
                'secret' => 'chat-kit-test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'assets' => ['enabled' => true],
            ]);
            $container->loadFromExtension('nowo_chat_kit', $chatKitConfig);
            // Expose the asset packages service so the smoke test can resolve bundle URLs.
            $container->setAlias(self::ASSET_PACKAGES_ALIAS, 'assets.packages')->setPublic(true);
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/nowo-chat-kit-bundle/'.md5(serialize($this->chatKitConfig)).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/nowo-chat-kit-bundle/logs';
    }
}

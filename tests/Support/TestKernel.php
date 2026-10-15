<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
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

    public const ENTITY_MANAGER_ALIAS = 'nowo_chat_kit_test.entity_manager';

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
        yield new DoctrineBundle();
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
            $container->loadFromExtension('doctrine', [
                'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
                'orm' => [
                    'mappings' => [
                        'TestSupport' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Nowo\\ChatKitBundle\\Tests\\Support\\Entity',
                        ],
                    ],
                ],
            ]);
            $container->loadFromExtension('nowo_chat_kit', array_replace_recursive(
                ['profiles' => ['default' => ['user_class' => TestUser::class]]],
                $chatKitConfig,
            ));
            // Expose the asset packages service so the smoke test can resolve bundle URLs.
            $container->setAlias(self::ASSET_PACKAGES_ALIAS, 'assets.packages')->setPublic(true);
            $container->setAlias(self::ENTITY_MANAGER_ALIAS, 'doctrine.orm.entity_manager')->setPublic(true);
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

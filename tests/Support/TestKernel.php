<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatPushSenderInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingPushSender;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAccessPolicy;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
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
                'translator' => ['enabled' => true, 'fallbacks' => ['en']],
                'default_locale' => 'en',
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
            $container->loadFromExtension('framework', ['cache' => ['app' => 'cache.adapter.array']]);
            foreach ([TestDirectory::class, TestAccessPolicy::class, TestChannelProvider::class, RecordingRealtime::class, RecordingPushSender::class] as $class) {
                $container->register($class)->setAutowired(true)->setPublic(true);
            }
            $container->setAlias(ChatDirectoryInterface::class, TestDirectory::class);
            $container->setAlias(ChatAccessPolicyInterface::class, TestAccessPolicy::class);
            $container->setAlias(ChatChannelProviderInterface::class, TestChannelProvider::class);
            $container->setAlias(ChatRealtimeInterface::class, RecordingRealtime::class);
            $container->setAlias(ChatPushSenderInterface::class, RecordingPushSender::class);
            // Expose the bundle services to the tests.
            $container->addCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    foreach ($container->getDefinitions() as $id => $definition) {
                        if (str_starts_with($id, 'Nowo\\ChatKitBundle\\') && !str_contains($id, '\\Tests\\')) {
                            $definition->setPublic(true);
                        }
                    }
                }
            });
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

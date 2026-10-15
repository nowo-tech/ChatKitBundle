<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration;

use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Smoke test: the bundle boots in a real kernel with default configuration.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class BundleBootTest extends TestCase
{
    protected function tearDown(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir().'/nowo-chat-kit-bundle');
    }

    public function testKernelBootsWithDefaultConfiguration(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();

        try {
            $container = $kernel->getContainer();

            self::assertSame('default', $container->getParameter('nowo_chat_kit.default_profile'));
            self::assertSame(['default' => ['enabled' => true]], $container->getParameter('nowo_chat_kit.profiles'));

            $packages = $container->get(TestKernel::ASSET_PACKAGES_ALIAS);
            self::assertInstanceOf(Packages::class, $packages);
            self::assertSame('/bundles/nowochatkit/js/nowo-chat.js', $packages->getUrl('js/nowo-chat.js', 'nowo_chat_kit'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testBundleExposesExtensionAlias(): void
    {
        $extension = (new NowoChatKitBundle())->getContainerExtension();

        self::assertInstanceOf(NowoChatKitExtension::class, $extension);
        self::assertSame('nowo_chat_kit', $extension->getAlias());
    }

    public function testPrependIsNoopWithoutFrameworkExtension(): void
    {
        $container = new ContainerBuilder();
        (new NowoChatKitExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('framework'));
    }

    public function testPrependRegistersAssetPackage(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'framework';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });

        (new NowoChatKitExtension())->prepend($container);

        $framework = $container->getExtensionConfig('framework');
        self::assertSame(
            NowoChatKitExtension::ASSET_BASE_PATH,
            $framework[0]['assets']['packages']['nowo_chat_kit']['base_path'] ?? null,
        );
    }
}

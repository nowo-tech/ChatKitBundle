<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration;

use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
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
            self::assertSame(TestUser::class, $container->getParameter('nowo_chat_kit.user_class'));
            self::assertSame(365, $container->getParameter('nowo_chat_kit.retention_days'));

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

    public function testPrependMapsTheUserInterfaceAndEntities(): void
    {
        $container = $this->containerWith('doctrine');
        $container->loadFromExtension('nowo_chat_kit', ['profiles' => ['default' => ['user_class' => TestUser::class]]]);

        (new NowoChatKitExtension())->prepend($container);

        $orm = $container->getExtensionConfig('doctrine')[0]['orm'];
        self::assertSame([ChatUserInterface::class => TestUser::class], $orm['resolve_target_entities']);
        self::assertSame('Nowo\\ChatKitBundle\\Entity', $orm['mappings']['NowoChatKitBundle']['prefix']);
    }

    public function testPrependRequiresAUserClassWithDoctrine(): void
    {
        $container = $this->containerWith('doctrine');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nowo_chat_kit.profiles.default.user_class');

        (new NowoChatKitExtension())->prepend($container);
    }

    public function testLoadWithoutConfigurationUsesTheDefaultProfile(): void
    {
        $container = new ContainerBuilder();
        (new NowoChatKitExtension())->load([], $container);

        $profiles = $container->getParameter('nowo_chat_kit.profiles');
        self::assertIsArray($profiles);
        self::assertSame(['default'], array_keys($profiles));
        self::assertNull($container->getParameter('nowo_chat_kit.user_class'));
    }

    public function testPrependIsNoopWithoutFrameworkExtension(): void
    {
        $container = new ContainerBuilder();
        (new NowoChatKitExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('framework'));
    }

    public function testPrependRegistersAssetPackage(): void
    {
        $container = $this->containerWith('framework');

        (new NowoChatKitExtension())->prepend($container);

        $framework = $container->getExtensionConfig('framework');
        self::assertSame(
            NowoChatKitExtension::ASSET_BASE_PATH,
            $framework[0]['assets']['packages']['nowo_chat_kit']['base_path'] ?? null,
        );
    }

    private function containerWith(string $alias): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }
        });
        $container->registerExtension(new NowoChatKitExtension());

        return $container;
    }
}

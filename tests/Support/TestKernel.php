<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatPushSenderInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingPushSender;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAccessPolicy;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestLinkPreviewProvider;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Twig\Environment;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;

/**
 * Kernel for integration and functional tests: SQLite in memory, the test user, in-memory contracts and the
 * bundle routes under /chat.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

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
        yield new SecurityBundle();
        yield new TwigBundle();
        // REQ-TWIG-004: Twig Extra registered like in a Flex application.
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        yield new UXIconsBundle();
        yield new NowoChatKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-chat-kit-bundle/' . md5(serialize($this->chatKitConfig)) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-chat-kit-bundle/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'chat-kit-test',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'assets'                => ['enabled' => true],
            'translator'            => ['enabled' => true, 'fallbacks' => ['en']],
            'default_locale'        => 'en',
            'csrf_protection'       => true,
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // Filesystem, not array: the services resetter empties array pools between browser requests.
            'cache'  => ['app' => 'cache.adapter.filesystem'],
            'router' => ['utf8' => true],
        ]);
        $container->extension('security', [
            'providers' => ['test_users' => ['entity' => ['class' => TestUser::class, 'property' => 'publicId']]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'test_users']],
        ]);
        $container->extension('twig', ['strict_variables' => true, 'auto_reload' => true]);
        // Only `send` and `x` exist: the other dock icons fall back to the built-in SVG.
        $container->extension('ux_icons', ['icon_dir' => __DIR__ . '/icons', 'iconify' => ['enabled' => false]]);
        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'memory' => true],
            'orm'  => [
                'mappings' => [
                    'TestSupport' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => __DIR__ . '/Entity',
                        'prefix'    => 'Nowo\ChatKitBundle\Tests\Support\Entity',
                    ],
                ],
            ],
        ]);
        $container->extension('nowo_chat_kit', array_replace_recursive(
            ['profiles' => ['default' => ['user_class' => TestUser::class]]],
            $this->chatKitConfig,
        ));

        $services = $container->services()->defaults()->autowire()->public();
        foreach ([TestDirectory::class, TestAccessPolicy::class, TestChannelProvider::class, RecordingRealtime::class, RecordingPushSender::class, TestLinkPreviewProvider::class] as $class) {
            $services->set($class);
        }
        $services->alias(ChatDirectoryInterface::class, TestDirectory::class);
        $services->alias(ChatAccessPolicyInterface::class, TestAccessPolicy::class);
        $services->alias(ChatChannelProviderInterface::class, TestChannelProvider::class);
        $services->alias(ChatRealtimeInterface::class, RecordingRealtime::class);
        $services->alias(ChatPushSenderInterface::class, RecordingPushSender::class);
        $services->alias(ChatLinkPreviewProviderInterface::class, TestLinkPreviewProvider::class);
        $services->alias(self::ASSET_PACKAGES_ALIAS, 'assets.packages');
        $services->alias(self::ENTITY_MANAGER_ALIAS, 'doctrine.orm.entity_manager');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@NowoChatKitBundle/Resources/config/routes.php')->prefix('/chat');
        $routes->add('test_page', '/page')->controller([self::class, 'page']);
        $routes->add('test_project_page', '/projects/acme/page')->controller([self::class, 'page']);
    }

    /** A signed-in page of the host application: renders the dock. */
    public function page(Environment $twig): Response
    {
        return new Response($twig->render('@NowoChatKitBundle/dock.html.twig'));
    }

    protected function build(ContainerBuilder $container): void
    {
        // Expose the bundle services to the tests.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'Nowo\\ChatKitBundle\\') || str_starts_with($id, 'limiter.nowo_chat')) {
                        $definition->setPublic(true);
                    }
                }
            }
        });
    }
}

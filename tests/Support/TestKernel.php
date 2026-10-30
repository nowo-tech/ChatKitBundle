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
use Nowo\ChatKitBundle\Tests\Support\Chat\TestReferenceProvider;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCountingMiddleware;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
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
use Symfony\Component\Translation\DataCollectorTranslator;
use Symfony\UX\Icons\UXIconsBundle;
use Twig\Environment;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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

    /** Records every translation lookup, like the profiler does (missing messages included). */
    public const TRANSLATOR_COLLECTOR = 'nowo_chat_kit_test.translator_collector';

    /** Parameter listing the Messenger / Doctrine tags autoconfiguration gave the bundle services (id → tags). */
    public const TAGS_PARAMETER = 'nowo_chat_kit_test.tags';

    /**
     * @param array<string, mixed> $chatKitConfig
     */
    public function __construct(
        private readonly array $chatKitConfig = [],
        private readonly bool $hostControllersRoutesOnly = false,
    ) {
        // Own environment for the routing.controllers-only host: its container class must not collide with the
        // default one (PHP keeps the first declared class, whose lazy ghosts live in another cache dir).
        parent::__construct($hostControllersRoutesOnly ? 'test_controllers' : 'test', false);
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
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new NowoChatKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-chat-kit-bundle/' . md5(serialize([$this->chatKitConfig, $this->hostControllersRoutesOnly])) . '/cache';
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
        $container->extension('twig', ['strict_variables' => true, 'auto_reload' => true, 'paths' => [__DIR__ . '/templates' => 'ChatKitTest']]);
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
        // #references: one provider, autoconfigured through the interface's tag.
        $services->set(TestReferenceProvider::class)->autoconfigure();
        // Query-count regression tests (N+1): every SQL statement of the connection is recorded.
        $services->set(QueryCounter::class);
        $services->set(QueryCountingMiddleware::class)->tag('doctrine.middleware');
        $services->alias(ChatDirectoryInterface::class, TestDirectory::class);
        $services->alias(ChatAccessPolicyInterface::class, TestAccessPolicy::class);
        $services->alias(ChatChannelProviderInterface::class, TestChannelProvider::class);
        $services->alias(ChatRealtimeInterface::class, RecordingRealtime::class);
        $services->alias(ChatPushSenderInterface::class, RecordingPushSender::class);
        $services->alias(ChatLinkPreviewProviderInterface::class, TestLinkPreviewProvider::class);
        $services->alias(self::ASSET_PACKAGES_ALIAS, 'assets.packages');
        $services->alias(self::ENTITY_MANAGER_ALIAS, 'doctrine.orm.entity_manager');
        $services->set(self::TRANSLATOR_COLLECTOR, DataCollectorTranslator::class)->decorate('translator')->args([service('.inner')]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->hostControllersRoutesOnly) {
            // Symfony's default config/routes.yaml (`controllers: { resource: routing.controllers }`), no bundle import.
            $routes->import('routing.controllers');

            return;
        }

        $routes->import('@NowoChatKitBundle/Resources/config/routes.php')->prefix('/chat');
        $routes->add('test_page', '/page')->controller([self::class, 'page']);
        $routes->add('test_project_page', '/projects/acme/page')->controller([self::class, 'page']);
        $routes->add('test_bundler_page', '/bundler-page')->controller([self::class, 'bundlerPage']);
    }

    /** A signed-in page of the host application: renders the dock. */
    public function page(Environment $twig): Response
    {
        return new Response($twig->render('@NowoChatKitBundle/dock.html.twig'));
    }

    /** A bundler host (1.6): its own assets and Stimulus controller on the dock root, no template override. */
    public function bundlerPage(Environment $twig): Response
    {
        return new Response($twig->createTemplate("{{ include('@NowoChatKitBundle/dock.html.twig', {nowo_chat_manual: true, nowo_chat_assets: false, nowo_chat_root_attr: {'data-controller': 'chat-dock', class: 'host-dock', 'data-testid': 'ignored', 'data-flag': true, onclick: false, 'bad name': 'x'}}) }}")->render());
    }

    protected function build(ContainerBuilder $container): void
    {
        // Expose the bundle services to the tests and record the tags autoconfiguration gave them.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $tags = [];
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'Nowo\\ChatKitBundle\\') || str_starts_with($id, 'limiter.nowo_chat')) {
                        $definition->setPublic(true);
                    }
                    foreach (['messenger.message_handler', 'doctrine.event_listener'] as $tag) {
                        if (str_starts_with($id, 'Nowo\\ChatKitBundle\\') && $definition->hasTag($tag)) {
                            $tags[$id][$tag] = $definition->getTag($tag);
                        }
                    }
                }
                $container->setParameter(TestKernel::TAGS_PARAMETER, $tags);
            }
        });
    }
}

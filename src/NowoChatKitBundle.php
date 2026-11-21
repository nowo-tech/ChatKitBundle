<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle;

use Nowo\ChatKitBundle\DependencyInjection\Compiler\ChatKitPortsPass;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\PrivateEntityManagerReferencesPass;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\RealtimeTransportPass;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\RemovedPortsPass;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\RuntimeRetentionPass;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\SearchKitRecentsPass;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Floating chat for Symfony (direct messages, channels, support inbox).
 *
 * The application provides ChatDirectoryInterface and ChatAccessPolicyInterface (and optionally channels,
 * a realtime transport and a push sender); see docs/USAGE.md.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoChatKitBundle extends Bundle
{
    /** FormKit profile (nowo_form_kit.profiles.chat_kit) used by every bundle form type (REQ-TWIG-005 rule 11). */
    public const FORM_KIT_PROFILE = 'chat_kit';

    /** Translation domain of the bundle. */
    public const TRANSLATION_DOMAIN = 'NowoChatKitBundle';

    /**
     * Registers the bundle compiler passes (realtime transport selection and Twig template paths).
     *
     * @param ContainerBuilder $container The container being compiled
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // 2.0: a service still on a port interface removed in 2.0 fails the build (before Symfony drops it silently):
        // first while the loader errors are there, then again once #[AsDecorator] is read (AutowireAsDecoratorPass, 100).
        $container->addCompilerPass(new RemovedPortsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
        $container->addCompilerPass(new RemovedPortsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 99);
        $container->addCompilerPass(new RealtimeTransportPass());
        // 1.27: the framework-free port twins (after the realtime transport is chosen).
        $container->addCompilerPass(new ChatKitPortsPass());
        // 1.27 (the private entity manager): the rollback middleware before DoctrineBundle's MiddlewaresPass, and
        // the bundle services moved onto the private manager after autowiring.
        $container->addCompilerPass(new PrivateEntityManagerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        $container->addCompilerPass(new PrivateEntityManagerReferencesPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->addCompilerPass(new TwigPathsPass());
        // 1.32: `runtime.retention_days` puts the runtime-setting adapter behind the retention port (resolver required).
        $container->addCompilerPass(new RuntimeRetentionPass());
        // 1.37 (audit VA-3): the SearchKit provider only when SearchKit keeps no message text in the browser.
        $container->addCompilerPass(new SearchKitRecentsPass());
    }

    /**
     * Returns the bundle extension (alias `nowo_chat_kit`).
     *
     * @return ExtensionInterface The lazily created NowoChatKitExtension
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Symfony bundle extension lazy-init at boot; not request state
            $this->extension = new NowoChatKitExtension();
        }

        return $this->extension;
    }
}

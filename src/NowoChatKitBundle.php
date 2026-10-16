<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle;

use Nowo\ChatKitBundle\DependencyInjection\Compiler\RealtimeTransportPass;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
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
    /**
     * Registers the bundle compiler passes (realtime transport selection and Twig template paths).
     *
     * @param ContainerBuilder $container The container being compiled
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new RealtimeTransportPass());
        $container->addCompilerPass(new TwigPathsPass());
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

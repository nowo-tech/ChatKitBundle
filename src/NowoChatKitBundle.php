<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle;

use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Floating chat for Symfony (direct messages, channels, support inbox).
 *
 * This is the 1.0 skeleton: it registers the `nowo_chat_kit` configuration and the
 * `nowo_chat_kit` asset package. Domain services are added in later iterations.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoChatKitBundle extends Bundle
{
    /**
     * Returns the bundle extension (alias `nowo_chat_kit`).
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

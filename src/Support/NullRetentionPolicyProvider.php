<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;

/**
 * Default: the application provides nothing, so the configured `retention_days` applies. Stateless.
 */
final class NullRetentionPolicyProvider implements RetentionPolicyProviderInterface
{
    /**
     * Provides nothing.
     *
     * @return int|null Always null
     */
    public function retentionDays(): ?int
    {
        return null;
    }
}

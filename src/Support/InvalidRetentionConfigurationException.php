<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use RuntimeException;

use function sprintf;

/**
 * The configured `retention_days` is not a positive number (0 or negative, for example an `%env(int:…)%` that coerced
 * garbage to 0). `nowo:chat:purge` refuses to run with it (fail closed). Holds the number only, never a secret.
 */
final class InvalidRetentionConfigurationException extends RuntimeException
{
    /**
     * Creates the InvalidRetentionConfigurationException with its collaborators.
     */
    public function __construct(public readonly int $days)
    {
        parent::__construct(sprintf('The configured retention_days (%d) must be a positive number of days.', $days));
    }
}

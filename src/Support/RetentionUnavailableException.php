<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use RuntimeException;
use Throwable;

use function sprintf;

/**
 * 1.32: the retention could not be read from its run-time source (the runtime setting is invalid, missing while
 * required, or its source failed). `nowo:chat:purge` refuses to run (fail closed): it never falls back to the configured
 * `retention_days`, to a default or to "keep forever". The message names the setting and the reason, never a stored
 * value.
 */
final class RetentionUnavailableException extends RuntimeException
{
    /**
     * @param string $setting The runtime setting id (`chat.retention_days`, `CHAT_RETENTION_DAYS`)
     * @param string $reason Why the value is unusable (no value quoted)
     */
    public function __construct(
        public readonly string $setting,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('The chat retention from runtime setting "%s" is unusable: %s', $setting, $reason), 0, $previous);
    }
}

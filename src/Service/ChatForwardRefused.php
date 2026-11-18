<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use RuntimeException;

/**
 * 1.36: a forward was refused for one of its targets (no SEND there, a ticket for a non-agent, a person one may not
 * write to, the application's veto). Nothing was written.
 */
final class ChatForwardRefused extends RuntimeException
{
    /**
     * @param int $target Index of the refused target in the request
     * @param string $reason The veto's reason key ('' when none)
     */
    public function __construct(
        public readonly int $target,
        public readonly string $reason = '',
    ) {
        parent::__construct('forward_forbidden');
    }
}

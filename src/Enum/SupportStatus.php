<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

/**
 * Lifecycle status of a support ticket: open (awaiting an agent), waiting for the user, resolved or closed
 * (read-only). A resolved ticket also turns read-only once `support.close_after_days` have passed.
 */
enum SupportStatus: string
{
    case Open     = 'open';
    case Waiting  = 'waiting';
    case Resolved = 'resolved';
    case Closed   = 'closed';

    /**
     * Whether the ticket still expects an answer (open or waiting for the user).
     *
     * @return bool True for open and waiting
     */
    public function isActive(): bool
    {
        return $this === self::Open || $this === self::Waiting;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

/**
 * Lifecycle status of a support thread: open (awaiting an agent) or resolved.
 */
enum SupportStatus: string
{
    case Open     = 'open';
    case Resolved = 'resolved';
}

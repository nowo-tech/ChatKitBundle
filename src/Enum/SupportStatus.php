<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

enum SupportStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}

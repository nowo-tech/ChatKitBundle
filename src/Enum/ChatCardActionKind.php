<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

/**
 * 2.6 (spec 259): the kind of a card action. Every kind is a same-origin link of the host (or its dialog); ChatKit
 * never runs anything itself.
 */
enum ChatCardActionKind: string
{
    /** Opens the item (reveal, view). */
    case Open = 'open';

    /** Asks for access to a locked item. */
    case RequestAccess = 'request_access';

    /** Any other host action. */
    case Host = 'host';
}

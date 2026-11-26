<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

/**
 * 2.6 (spec 259): what a viewer may do with a typed card. A card the viewer may not even know about is left out of
 * the provider's answer (Hidden): the neutral "Content not visible" chip, the same as a reference.
 */
enum ChatCardState: string
{
    /** The viewer may use the item (Beacon: a vault grant, a one-time secret not yet burnt). */
    case Open = 'open';

    /** The viewer may know the item exists but not open it (Beacon: "Request access"). */
    case Locked = 'locked';
}

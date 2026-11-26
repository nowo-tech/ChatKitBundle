<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

/**
 * 2.6 (spec 259): what a message menu slot acts on.
 */
enum ChatMenuSlotTarget: string
{
    /** The message (its dialog gets `?conversation=&message=`). */
    case Message = 'message';

    /**
     * The text the viewer selected in the message. The selection never travels in a URL or to ChatKit's server: the
     * dock hands it to the host dialog in the browser (`nowo-chat:slot-payload`).
     */
    case Selection = 'selection';

    /** A card of the message whose type is in the slot's card types (its dialog also gets `&card=<type>:<id>`). */
    case Card = 'card';
}

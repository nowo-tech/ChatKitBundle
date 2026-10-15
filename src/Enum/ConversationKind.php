<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

enum ConversationKind: string
{
    /** 1:1 between two people allowed by the access policy. */
    case Direct = 'direct';

    /** An application-defined group conversation (ChatChannelProviderInterface). */
    case Channel = 'channel';

    /** One user's thread with the shared support inbox. */
    case Support = 'support';
}

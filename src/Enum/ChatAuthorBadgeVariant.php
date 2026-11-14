<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

use Nowo\ChatKitBundle\Model\ChatAuthorBadge;

/**
 * 1.29 (Beacon spec 234, author badges): how a {@see ChatAuthorBadge} looks. An allow-list, never a CSS class from the
 * host: the bundle maps each variant to its own class (`nowo-chat-author-badge--<variant>`). The same values as
 * TaskBoard's member badges.
 */
enum ChatAuthorBadgeVariant: string
{
    case Neutral = 'neutral';
    case Info    = 'info';
    case Success = 'success';
    case Warning = 'warning';
    /** A service account (an API token, an MCP agent). */
    case Agent = 'agent';
}

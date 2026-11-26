<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Enum;

/**
 * 2.7 (spec 259): what a chat attachment is, from its sniffed type and its extension (they must agree). Decides the
 * file card the clients draw and, from 2.8, the preview built for it. `Archive` and `Other` are opt-in.
 */
enum ChatAttachmentKind: string
{
    case Image    = 'image';
    case Pdf      = 'pdf';
    case Text     = 'text';
    case Markdown = 'markdown';
    case Code     = 'code';
    case Office   = 'office';
    case Audio    = 'audio';
    case Video    = 'video';
    case Archive  = 'archive';
    case Other    = 'other';
}

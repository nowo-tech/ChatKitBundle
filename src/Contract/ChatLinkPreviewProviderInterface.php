<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatLinkPreview;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Link previews resolved **per viewer** (optional; default: none). Return a preview only for links the viewer may
 * see — never leak the title of something they cannot open.
 */
interface ChatLinkPreviewProviderInterface
{
    /**
     * @param list<string> $urls http(s) or root-relative URLs found in messages (at most 10 per call)
     *
     * @return array<string, ChatLinkPreview> url => preview; unknown or invisible URLs are left out
     */
    public function previews(ChatUserInterface $viewer, array $urls): array;
}

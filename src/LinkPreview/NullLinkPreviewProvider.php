<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\LinkPreview;

use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Model\ChatLinkPreview;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Default: no link previews (the dock does not ask for them).
 */
final class NullLinkPreviewProvider implements ChatLinkPreviewProviderInterface
{
    /**
     * Resolves nothing.
     *
     * @param ChatUserInterface $viewer Ignored
     * @param list<string> $urls Ignored
     *
     * @return array<string, ChatLinkPreview> Always empty
     */
    public function previews(ChatUserInterface $viewer, array $urls): array
    {
        return [];
    }
}

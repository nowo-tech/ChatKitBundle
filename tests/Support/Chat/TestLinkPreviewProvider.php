<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Model\ChatLinkPreview;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Previews links under /projects/acme/ for everyone but strangers (who cannot see Acme).
 */
final class TestLinkPreviewProvider implements ChatLinkPreviewProviderInterface
{
    public function previews(ChatUserInterface $viewer, array $urls): array
    {
        if (str_starts_with($viewer->getChatDisplayName(), 'Stranger')) {
            return [];
        }
        $previews = [];
        foreach ($urls as $url) {
            if (str_starts_with($url, '/projects/acme/')) {
                $previews[$url] = new ChatLinkPreview('Acme · '.basename($url), 'Issue', 'open');
            }
        }
        // A provider returning extra keys does not leak them.
        $previews['/not-asked'] = new ChatLinkPreview('extra');

        return $previews;
    }
}

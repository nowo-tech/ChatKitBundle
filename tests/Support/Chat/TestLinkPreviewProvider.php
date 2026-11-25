<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Model\ChatLinkPreview;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function count;

/**
 * Previews links under /projects/acme/ (2.4: and https://example.test/projects/acme/) for everyone but strangers (who cannot
 * see Acme). Records how many URLs each call asked about.
 */
final class TestLinkPreviewProvider implements ChatLinkPreviewProviderInterface
{
    /** @var list<int> */
    public array $calls = [];

    public function previews(ChatUserInterface $viewer, array $urls): array
    {
        $this->calls[] = count($urls);
        if (str_starts_with($viewer->getChatDisplayName(), 'Stranger')) {
            return [];
        }
        $previews = [];
        foreach ($urls as $url) {
            if (str_starts_with($url, '/projects/acme/') || str_starts_with($url, 'https://example.test/projects/acme/')) {
                $previews[$url] = new ChatLinkPreview('Acme · ' . basename($url), 'Issue', 'open');
            }
        }
        // A provider returning extra keys does not leak them.
        $previews['/not-asked'] = new ChatLinkPreview('extra');

        return $previews;
    }
}

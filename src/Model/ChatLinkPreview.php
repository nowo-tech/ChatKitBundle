<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * A card shown under a message for a link the viewer may see (e.g. an issue of the application): plain text only.
 */
final readonly class ChatLinkPreview
{
    public function __construct(
        public string $title,
        public string $subtitle = '',
        /** Short status / type label (e.g. "Open", "error"). */
        public string $badge = '',
    ) {
    }

    /**
     * @return array{title: string, subtitle: string, badge: string}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'subtitle' => $this->subtitle, 'badge' => $this->badge];
    }
}

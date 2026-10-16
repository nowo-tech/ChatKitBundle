<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * A card shown under a message for a link the viewer may see (e.g. an issue of the application): plain text only.
 */
final readonly class ChatLinkPreview
{
    /**
     * Builds a link preview card from plain-text title, subtitle and badge.
     *
     * @param string $title Card title
     * @param string $subtitle Secondary line; empty hides it
     * @param string $badge Short status / type label; empty hides it
     */
    public function __construct(
        public string $title,
        public string $subtitle = '',
        /** Short status / type label (e.g. "Open", "error"). */
        public string $badge = '',
    ) {
    }

    /**
     * Serializes the card for the JSON API.
     *
     * @return array{title: string, subtitle: string, badge: string} The card fields
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'subtitle' => $this->subtitle, 'badge' => $this->badge];
    }
}

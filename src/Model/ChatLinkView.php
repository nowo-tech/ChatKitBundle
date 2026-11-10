<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;

/**
 * 1.24: read-only snapshot of a pinned link for event listeners and application ports (DDD hosts).
 */
final readonly class ChatLinkView
{
    /**
     * @param string $uuid Public uuid of the link
     * @param string $conversationUuid Public uuid of its conversation
     * @param string $url The link
     * @param string|null $title Its title, if any
     * @param string|null $createdByPublicId Who pinned it; null once the account is gone
     * @param DateTimeImmutable $createdAt When it was pinned
     */
    public function __construct(
        public string $uuid,
        public string $conversationUuid,
        public string $url,
        public ?string $title,
        public ?string $createdByPublicId,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Snapshot of a pinned link.
     *
     * @param ChatConversationLink $link The link
     *
     * @return self The view
     */
    public static function of(ChatConversationLink $link): self
    {
        return new self($link->getUuid(), $link->getConversation()->getUuid(), $link->getUrl(), $link->getTitle(), $link->getCreatedBy()?->getChatPublicId(), $link->getCreatedAt());
    }
}

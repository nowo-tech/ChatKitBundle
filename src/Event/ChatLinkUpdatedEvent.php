<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Model\ChatLinkView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.19: a pinned link changed its URL or its title (after the flush, before the `links` broadcast). Not dispatched when
 * nothing changed.
 */
final readonly class ChatLinkUpdatedEvent
{
    /**
     * @param ChatConversationLink $link The pinned link, changed
     * @param ChatUserInterface $by Who changed it
     * @param string $previousUrl The URL before the change
     * @param string|null $previousTitle The title before the change
     */
    public function __construct(
        public ChatConversationLink $link,
        public ChatUserInterface $by,
        public string $previousUrl,
        public ?string $previousTitle,
    ) {
    }

    /**
     * 1.24: the link as a read-only snapshot (scalars and public ids), for listeners that must not keep the entity.
     *
     * @return ChatLinkView The snapshot
     */
    public function linkView(): ChatLinkView
    {
        return ChatLinkView::of($this->link);
    }

    /**
     * 1.24: public id of `by`.
     *
     * @return string The public id
     */
    public function byPublicId(): string
    {
        return $this->by->getChatPublicId();
    }
}

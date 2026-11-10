<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Model\ChatLinkView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.19: a link was pinned to a conversation (after the flush, before the `links` broadcast).
 */
final readonly class ChatLinkAddedEvent
{
    /**
     * @param ChatConversationLink $link The new pinned link (its conversation: `$link->getConversation()`)
     * @param ChatUserInterface $by Who added it
     */
    public function __construct(
        public ChatConversationLink $link,
        public ChatUserInterface $by,
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

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
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatConversationLink $link,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
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

    /**
     * 2.0: the link uuid.
     */
    public function linkUuid(): string
    {
        return $this->link->getUuid();
    }

    /**
     * 2.0: the conversation uuid (no entity needed).
     */
    public function conversationUuid(): string
    {
        return $this->link->getConversation()->getUuid();
    }

    /**
     * 2.0: The user who pinned the link. The key the chat stores for the user: the host user id as a string (`getReference()` / compare with your own id).
     */
    public function byReference(): string
    {
        return EventRefs::user($this->by);
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function linkId(): int
    {
        return EventRefs::id($this->link->getId());
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function conversationId(): int
    {
        return EventRefs::id($this->link->getConversation()->getId());
    }
}

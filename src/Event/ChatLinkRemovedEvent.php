<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.19: a pinned link was removed from a conversation (after the flush, before the `links` broadcast). The row is gone:
 * the event carries what it held.
 */
final readonly class ChatLinkRemovedEvent
{
    /**
     * @param ChatConversation $conversation The conversation
     * @param string $uuid The public id the link had
     * @param string $url Its URL
     * @param string|null $title Its title
     * @param ChatUserInterface $by Who removed it
     */
    public function __construct(
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public ChatConversation $conversation,
        public string $uuid,
        public string $url,
        public ?string $title,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
        public ChatUserInterface $by,
    ) {
    }

    /**
     * 1.24: the conversation as a read-only snapshot (scalars and public ids), for listeners that must not keep the entity.
     *
     * @return ChatConversationView The snapshot
     */
    public function conversationView(): ChatConversationView
    {
        return ChatConversationView::of($this->conversation);
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
     * 2.0: the conversation uuid (no entity needed).
     */
    public function conversationUuid(): string
    {
        return $this->conversation->getUuid();
    }

    /**
     * 2.0: The user who removed the link. The key the chat stores for the user: the host user id as a string (`getReference()` / compare with your own id).
     */
    public function byReference(): string
    {
        return EventRefs::user($this->by);
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function conversationId(): int
    {
        return EventRefs::id($this->conversation->getId());
    }
}

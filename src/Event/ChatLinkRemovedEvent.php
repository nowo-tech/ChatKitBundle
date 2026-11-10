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
        public ChatConversation $conversation,
        public string $uuid,
        public string $url,
        public ?string $title,
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
}

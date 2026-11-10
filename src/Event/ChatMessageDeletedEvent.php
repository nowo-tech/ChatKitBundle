<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * A message was deleted; `moderated` when someone other than the author (a support agent) removed it — audit it.
 */
final readonly class ChatMessageDeletedEvent
{
    /**
     * Builds the event from the deleted message, who deleted it and whether it was a moderation.
     *
     * @param ChatMessage $message The deleted message (now a tombstone)
     * @param ChatUserInterface $by The user who deleted it
     * @param bool $moderated True when someone other than the author removed it
     */
    public function __construct(
        public ChatMessage $message,
        public ChatUserInterface $by,
        public bool $moderated,
    ) {
    }

    /**
     * 1.24: the message as a read-only snapshot (scalars and public ids), for listeners that must not keep the entity.
     *
     * @return ChatMessageView The snapshot
     */
    public function messageView(): ChatMessageView
    {
        return ChatMessageView::of($this->message);
    }

    /**
     * 1.24: public id of who deleted it.
     *
     * @return string The public id
     */
    public function byPublicId(): string
    {
        return $this->by->getChatPublicId();
    }
}

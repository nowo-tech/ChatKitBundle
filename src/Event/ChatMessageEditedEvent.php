<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;

/**
 * 1.36: a message was edited, dispatched after the commit.
 */
final readonly class ChatMessageEditedEvent
{
    /**
     * @param ChatMessage $message The edited message
     * @param int|null $revisionId Id of the stored previous text (`message_menu.edit_history`); null when none is kept
     */
    public function __construct(
        public ChatMessage $message,
        public ?int $revisionId = null,
    ) {
    }

    /**
     * The message as a read-only snapshot.
     */
    public function messageView(): ChatMessageView
    {
        return ChatMessageView::of($this->message);
    }
}

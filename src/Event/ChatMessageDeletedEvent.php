<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * A message was deleted; `moderated` when someone other than the author (a support agent) removed it — audit it.
 */
final readonly class ChatMessageDeletedEvent
{
    public function __construct(
        public ChatMessage $message,
        public ChatUserInterface $by,
        public bool $moderated,
    ) {
    }
}

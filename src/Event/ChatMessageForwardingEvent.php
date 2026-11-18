<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.36: a message is about to be forwarded to one target, dispatched once per target before anything is written.
 * A listener calls deny() (with a reason key for the client) to refuse it: then the whole forward is refused
 * (403 `forward_forbidden`) and nothing is written.
 */
final class ChatMessageForwardingEvent
{
    private bool $denied   = false;
    private string $reason = '';

    /**
     * @param ChatMessage $source The message to forward
     * @param ChatUserInterface $actor Who forwards it
     * @param ChatConversation|null $target The target conversation; null for a direct conversation not opened yet
     * @param ChatUserInterface|null $recipient The other person of that new direct conversation (null otherwise)
     */
    public function __construct(
        public readonly ChatMessage $source,
        public readonly ChatUserInterface $actor,
        public readonly ?ChatConversation $target,
        public readonly ?ChatUserInterface $recipient = null,
    ) {
    }

    /**
     * Refuses the forward.
     *
     * @param string $reason A short key the client may show (letters, digits, `_`, `.`, `-`), or ''
     */
    public function deny(string $reason = ''): void
    {
        // @igor-ignore - An event object of one dispatch (one forward target), not a shared worker service.
        $this->denied = true;
        // @igor-ignore - An event object of one dispatch (one forward target), not a shared worker service.
        $this->reason = preg_match('/^[A-Za-z0-9_.-]{0,64}$/', $reason) === 1 ? $reason : '';
    }

    /**
     * Whether a listener refused the forward.
     */
    public function isDenied(): bool
    {
        return $this->denied;
    }

    /**
     * The reason key of the refusal ('' when none was given).
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The source message as a read-only snapshot, for listeners that must not keep the entity.
     */
    public function sourceView(): ChatMessageView
    {
        return ChatMessageView::of($this->source);
    }
}

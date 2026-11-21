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
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public readonly ChatMessage $source,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
        public readonly ChatUserInterface $actor,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this entity); removed in 3.0 */
        public readonly ?ChatConversation $target,
        /** @deprecated since 2.0, use the id getters (a private entity manager does not manage this user); removed in 3.0 */
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

    /**
     * 2.0: the source message uuid.
     */
    public function sourceUuid(): string
    {
        return $this->source->getUuid();
    }

    /**
     * 2.0: the conversation uuid of the source message.
     */
    public function sourceConversationUuid(): string
    {
        return $this->source->getConversation()->getUuid();
    }

    /**
     * 2.0: the target conversation uuid; null when the target is a recipient without a conversation yet.
     */
    public function targetConversationUuid(): ?string
    {
        return $this->target?->getUuid();
    }

    /**
     * 2.0: The user who forwards. The key the chat stores for the user: the host user id as a string (`getReference()` / compare with your own id).
     */
    public function actorReference(): string
    {
        return EventRefs::user($this->actor);
    }

    /**
     * 2.0: the user public id.
     */
    public function actorPublicId(): string
    {
        return $this->actor->getChatPublicId();
    }

    /**
     * 2.0: The direct-message recipient. The key the chat stores for the user (the host user id as a string); null for none.
     */
    public function recipientReference(): ?string
    {
        return EventRefs::optional($this->recipient);
    }

    /**
     * 2.0: the user public id.
     */
    public function recipientPublicId(): ?string
    {
        return $this->recipient?->getChatPublicId();
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function sourceId(): int
    {
        return EventRefs::id($this->source->getId());
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager.
     */
    public function sourceConversationId(): int
    {
        return EventRefs::id($this->source->getConversation()->getId());
    }

    /**
     * 2.0: the primary key of the same row, for `getReference()` on your own manager; null when there is none.
     */
    public function targetConversationId(): ?int
    {
        return $this->target?->getId();
    }
}

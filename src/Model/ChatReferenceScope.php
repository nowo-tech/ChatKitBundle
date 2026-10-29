<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use Nowo\ChatKitBundle\Entity\ChatConversation;

/**
 * Who a reference must be readable by, and where it is written. Searching and validating a message use the
 * conversation's scope: the writer plus, in a direct conversation, the other person (an item only one of them may
 * open is never offered nor kept), and the conversation itself (a provider limits a channel to its own items).
 * Rendering uses the reader scope: the viewer alone, no conversation.
 */
final readonly class ChatReferenceScope
{
    /**
     * Builds a scope.
     *
     * @param ChatUserInterface $viewer The person searching, writing or reading
     * @param list<ChatUserInterface> $audience Everyone who must be able to open the item (the viewer included)
     * @param ChatConversation|null $conversation The conversation written in; null when resolving for display
     */
    public function __construct(
        public ChatUserInterface $viewer,
        public array $audience,
        public ?ChatConversation $conversation = null,
    ) {
    }

    /**
     * The scope of a reader: the viewer alone, no conversation (render time).
     *
     * @param ChatUserInterface $viewer The reader
     *
     * @return self The scope
     */
    public static function reader(ChatUserInterface $viewer): self
    {
        return new self($viewer, [$viewer]);
    }

    /**
     * The channel key of the conversation (e.g. a project channel); null for direct and support conversations and at
     * render time.
     *
     * @return string|null The key
     */
    public function channelKey(): ?string
    {
        return $this->conversation?->isChannel() === true ? $this->conversation->getChannelKey() : null;
    }
}

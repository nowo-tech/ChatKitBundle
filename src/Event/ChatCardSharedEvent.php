<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

/**
 * 2.6 (spec 259): a message with a typed card was sent, dispatched after the commit, once per card of the message.
 * Ids only: never a title, a value or the message text (audit trails may store it as is).
 */
final readonly class ChatCardSharedEvent
{
    /**
     * @param string $cardType The card type (`vault_item`)
     * @param string $cardId The opaque card id
     * @param string $conversationUuid The conversation
     * @param string $messageUuid The message holding the card
     * @param string $authorPublicId Who shared it
     */
    public function __construct(
        public string $cardType,
        public string $cardId,
        public string $conversationUuid,
        public string $messageUuid,
        public string $authorPublicId,
    ) {
    }
}

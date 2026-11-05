<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Privacy\ChatPersonalData;

/**
 * 1.18 (DATA-CHAT-MENTION-ERASE): the `@[Name](publicId)` mentions of an erased user were rewritten to a label in other
 * people's messages ({@see ChatPersonalData::eraseMentions()}, also run by {@see ChatPersonalData::erase()}). Hosts
 * refresh their own copies (search indexes, caches, notification history) of the listed messages, and report or fix the
 * skipped ones. Dispatched after the erasure's own commit: when the caller wraps the call in an outer transaction, it
 * fires before that outer commit. Dispatched before the `message_updated` broadcast (audit FT-L2). Not dispatched when
 * no message was rewritten or skipped.
 */
final readonly class ChatMentionsErasedEvent
{
    /**
     * @param string $publicId the public id of the erased user
     * @param list<int> $messageIds the messages whose body and HTML were rewritten
     * @param list<int> $skippedMessageIds candidate messages left untouched (text that could not be scanned); they still
     *                                     hold the mention
     */
    public function __construct(
        public string $publicId,
        public array $messageIds,
        public array $skippedMessageIds = [],
    ) {
    }
}

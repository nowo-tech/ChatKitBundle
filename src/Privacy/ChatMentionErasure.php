<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Privacy;

use Countable;

use function count;

/**
 * What {@see ChatPersonalData::eraseMentions()} did (1.18, DATA-CHAT-MENTION-ERASE). Countable: the messages that were
 * rewritten.
 *
 * Skipped messages still hold the mention: their text could not be scanned (invalid UTF-8, or another regex failure), or
 * the rewrite would have produced a mention that was not there before (text built around the token), so they were left
 * untouched rather than overwritten. Fix or delete them by hand, then run the erasure again.
 */
final readonly class ChatMentionErasure implements Countable
{
    /**
     * @param string $publicId the public id of the erased user (the id in `@[Name](publicId)`)
     * @param list<int> $messageIds the messages whose body and HTML were rewritten
     * @param list<int> $skippedMessageIds candidate messages left untouched (see the class description)
     */
    public function __construct(
        public string $publicId = '',
        public array $messageIds = [],
        public array $skippedMessageIds = [],
    ) {
    }

    /** The messages that were rewritten. */
    public function count(): int
    {
        return count($this->messageIds);
    }

    /** The messages left untouched because they could not be rewritten safely. */
    public function skipped(): int
    {
        return count($this->skippedMessageIds);
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

/**
 * 2.0: a conversation was deleted for good by a host call (ChatMessagingInterface::deleteConversation() or
 * purgeChannel()), with its messages and every row that hung on them. Dispatched once, after the commit, by the call that
 * deleted it (a concurrent call that found it gone dispatches nothing). Ids only: the rows no longer exist, so there is
 * no entity to hand over. Use it to drop your own references (a search index, a projection, an audit trail).
 */
final readonly class ChatConversationDeletedEvent
{
    /**
     * @param int $conversationId Primary key the conversation had
     * @param string $conversationUuid Public uuid the conversation had
     * @param string $kind `direct`, `channel` or `support`
     * @param string|null $channelKey The application channel key of a channel; null for other kinds
     * @param list<int> $messageIds Primary keys of the deleted messages (thread replies included)
     */
    public function __construct(
        public int $conversationId,
        public string $conversationUuid,
        public string $kind,
        public ?string $channelKey,
        public array $messageIds,
    ) {
    }

    /**
     * The primary key the conversation had.
     */
    public function conversationId(): int
    {
        return $this->conversationId;
    }

    /**
     * The public uuid the conversation had.
     */
    public function conversationUuid(): string
    {
        return $this->conversationUuid;
    }
}

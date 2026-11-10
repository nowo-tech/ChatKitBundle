<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Service\ChatMentions;

/**
 * 1.24: read-only snapshot of a message for event listeners and application ports (DDD hosts): scalars and public ids
 * only, no Doctrine entity and no user object. Taken when it is built; it never changes afterwards.
 */
final readonly class ChatMessageView
{
    /**
     * @param int $id Internal id (also the read cursor)
     * @param string $uuid Public uuid
     * @param string $conversationUuid Public uuid of its conversation
     * @param string $conversationKind `direct`, `channel` or `support`
     * @param string|null $channelKey The application channel key of a channel message; null otherwise
     * @param string $kind `text`, `buzz`, `bot`, `pin`, `auto` or `system`
     * @param string|null $authorPublicId The author's public id; null for bot and system lines and once the account is gone
     * @param string $body The stored Markdown (a translation key for bot and system lines; empty once deleted)
     * @param string $bodyHtml The sanitized HTML render (empty for lines and once deleted)
     * @param list<string> $mentionedPublicIds Public ids of the @mention tokens in the body
     * @param string|null $mentionAll `channel` or `here` when the message mentioned a whole channel
     * @param bool $deleted Whether it is a tombstone
     * @param DateTimeImmutable $createdAt Creation time
     * @param DateTimeImmutable|null $editedAt Last edit; null when never edited
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public string $conversationUuid,
        public string $conversationKind,
        public ?string $channelKey,
        public string $kind,
        public ?string $authorPublicId,
        public string $body,
        public string $bodyHtml,
        public array $mentionedPublicIds,
        public ?string $mentionAll,
        public bool $deleted,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $editedAt,
    ) {
    }

    /**
     * Snapshot of a message.
     *
     * @param ChatMessage $message The message
     *
     * @return self The view
     */
    public static function of(ChatMessage $message): self
    {
        $conversation = $message->getConversation();
        $tokens       = $message->isDeleted() || $message->isBot() || $message->isSystem() ? [] : array_keys(ChatMentions::parse($message->getBody()));

        return new self(
            (int) $message->getId(),
            $message->getUuid(),
            $conversation->getUuid(),
            $conversation->getKind()->value,
            $conversation->getChannelKey(),
            $message->getKind(),
            $message->getAuthor()?->getChatPublicId(),
            $message->getBody(),
            $message->getBodyHtml(),
            array_values(array_map(strval(...), $tokens)),
            $message->getMentionAll(),
            $message->isDeleted(),
            $message->getCreatedAt(),
            $message->getEditedAt(),
        );
    }
}

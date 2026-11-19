<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Enum\ConversationKind;

use const DATE_ATOM;

/**
 * 1.37: one search result, built only from what the viewer may see: the conversation title as the viewer's list shows
 * it, the author's display name (null for a tombstone author or a system line), a plain-text excerpt and its marked
 * segments.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatMessageSearchHit
{
    /**
     * @param string $messageUuid The message
     * @param string|null $threadRootUuid The thread root when the message is a thread reply
     * @param string $conversationUuid Its conversation
     * @param ConversationKind $conversationKind Kind of the conversation
     * @param string $conversationTitle Title of the conversation for the viewer
     * @param string|null $authorName Display name of the author
     * @param string|null $authorPublicId Public id of the author
     * @param DateTimeImmutable $createdAt When it was written
     * @param string $snippet Plain-text excerpt
     * @param list<array{text: string, mark: bool}> $segments The excerpt, split with the words marked
     */
    public function __construct(
        public string $messageUuid,
        public ?string $threadRootUuid,
        public string $conversationUuid,
        public ConversationKind $conversationKind,
        public string $conversationTitle,
        public ?string $authorName,
        public ?string $authorPublicId,
        public DateTimeImmutable $createdAt,
        public string $snippet,
        public array $segments,
    ) {
    }

    /**
     * The row as the JSON endpoint sends it (plain text only).
     *
     * @return array{uuid: string, threadRootUuid: string|null, conversation: array{uuid: string, kind: string, title: string}, author: array{name: string, publicId: string|null}|null, createdAt: string, snippet: string}
     */
    public function toArray(): array
    {
        return [
            'uuid'           => $this->messageUuid,
            'threadRootUuid' => $this->threadRootUuid,
            'conversation'   => ['uuid' => $this->conversationUuid, 'kind' => $this->conversationKind->value, 'title' => $this->conversationTitle],
            'author'         => $this->authorName === null ? null : ['name' => $this->authorName, 'publicId' => $this->authorPublicId],
            'createdAt'      => $this->createdAt->format(DATE_ATOM),
            'snippet'        => $this->snippet,
        ];
    }
}

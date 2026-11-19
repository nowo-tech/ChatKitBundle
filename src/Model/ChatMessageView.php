<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Search\ChatSearchText;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatMentions;
use Nowo\ChatKitBundle\Service\ChatReferences;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

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
     * @param string|null $threadRootUuid 1.36: public uuid of the thread root of a thread reply; null in the main timeline
     * @param int $threadReplyCount 1.36: replies of the thread this message starts
     * @param bool $forwarded 1.36: whether the message is a forward
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
        public ?string $threadRootUuid = null,
        public int $threadReplyCount = 0,
        public bool $forwarded = false,
    ) {
    }

    /**
     * Snapshot of a message.
     *
     * @param ChatMessage $message The message
     *
     * @return self The view
     */
    public static function of(ChatMessage $message, ?ChatMarkdownRenderer $markdown = null): self
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
            // 1.37 (audits VA3-7, VA4-6): never a stored render that shows a #reference title; a new render goes through
            // the application's configured renderer, and without one the text is shown as escaped plain text.
            $markdown instanceof ChatMarkdownRenderer ? $markdown->htmlOf($message) : self::safeHtml($message),
            array_values(array_map(strval(...), $tokens)),
            $message->getMentionAll(),
            $message->isDeleted(),
            $message->getCreatedAt(),
            $message->getEditedAt(),
            $message->getThreadRoot()?->getUuid(),
            $message->getThreadReplyCount(),
            $message->isForwarded(),
        );
    }

    /**
     * The stored render, or (audit VA3-7) the escaped plain text of a body whose stored render may show a `#reference`
     * title — plain text holds no title (`#…`).
     */
    private static function safeHtml(ChatMessage $message): string
    {
        if (!ChatReferences::hasUnsafeTitles($message->getBody())) {
            return $message->getBodyHtml();
        }

        return '<p>' . htmlspecialchars(ChatMarkdownRenderer::plain($message->getBody(), ChatSearchText::INDEXABLE_LENGTH), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '</p>';
    }
}

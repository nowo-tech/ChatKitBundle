<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

use DateTimeImmutable;

use function mb_strlen;
use function preg_replace;
use function trim;

/**
 * 1.37: what a viewer searches for: the trimmed text and its words, optional filters (one conversation, one author, a
 * date range, "has a link") and the keyset cursor. Every filter value is client input: the search treats an unknown and
 * a forbidden value alike (no rows). Built through {@see self::create()}, which enforces the length bounds.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatMessageSearchQuery
{
    /** The author filter value meaning "the viewer". */
    public const string ME = 'me';

    /** Longest filter value accepted (a uuid or a public id); longer values match nothing. */
    public const int MAX_FILTER_LENGTH = 64;

    /** @var list<string> The words of the text ({@see ChatSearchText::words()}) */
    public array $words;

    /**
     * @param string $text Trimmed text (control characters removed)
     * @param string|null $conversation Uuid of one conversation, or null for every conversation
     * @param string|null $author Public id of the author, {@see self::ME}, or null for any author
     * @param DateTimeImmutable|null $after Only messages created at or after this time
     * @param DateTimeImmutable|null $before Only messages created before this time
     * @param bool $hasLink Only messages with a link
     * @param string|null $cursor Uuid of the last row of the previous page, or null for the first page
     */
    private function __construct(
        public string $text,
        public ?string $conversation,
        public ?string $author,
        public ?DateTimeImmutable $after,
        public ?DateTimeImmutable $before,
        public bool $hasLink,
        public ?string $cursor,
    ) {
        $this->words = ChatSearchText::words($text);
    }

    /**
     * Builds a query, checking the text length.
     *
     * @param string $text The raw text
     * @param int $minLength Shortest trimmed text, in characters
     * @param int $maxLength Longest trimmed text, in characters
     * @param string|null $conversation See the constructor (empty = null)
     * @param string|null $author See the constructor (empty = null)
     * @param DateTimeImmutable|null $after See the constructor
     * @param DateTimeImmutable|null $before See the constructor
     * @param bool $hasLink See the constructor
     * @param string|null $cursor See the constructor (empty = null)
     *
     * @throws ChatSearchQueryException When the text is shorter or longer than allowed
     *
     * @return self The query
     */
    public static function create(string $text, int $minLength, int $maxLength, ?string $conversation = null, ?string $author = null, ?DateTimeImmutable $after = null, ?DateTimeImmutable $before = null, bool $hasLink = false, ?string $cursor = null): self
    {
        $text   = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
        $length = mb_strlen($text);
        if ($length > $maxLength) {
            throw new ChatSearchQueryException(ChatSearchQueryException::TOO_LONG);
        }
        if ($length < $minLength) {
            throw new ChatSearchQueryException(ChatSearchQueryException::TOO_SHORT);
        }
        $blank = static fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value);

        return new self($text, $blank($conversation), $blank($author), $after, $before, $hasLink, $blank($cursor));
    }
}

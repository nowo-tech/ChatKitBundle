<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

/**
 * 1.37: one page of search results and the cursor of the next one (null at the end). Never a total: a count would
 * have to be exact for the viewer, and the rows are re-checked after the SQL filter.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatMessageSearchPage
{
    /**
     * @param list<ChatMessageSearchHit> $hits The rows, newest first
     * @param string|null $next Cursor of the next page (a message uuid), or null
     */
    public function __construct(
        public array $hits,
        public ?string $next,
    ) {
    }

    /**
     * An empty last page (also the answer to anything the viewer may not read).
     *
     * @return self The page
     */
    public static function empty(): self
    {
        return new self([], null);
    }
}

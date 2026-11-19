<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

use InvalidArgumentException;

/**
 * 1.37: a search text outside the length bounds (`search.min_query_length` / `search.max_query_length`). The code is
 * the API error code; the message never holds the text.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatSearchQueryException extends InvalidArgumentException
{
    public const string TOO_LONG = 'query_too_long';

    public const string TOO_SHORT = 'query_too_short';

    /**
     * @param string $errorCode {@see self::TOO_LONG} or {@see self::TOO_SHORT}
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('The search text is ' . ($errorCode === self::TOO_LONG ? 'too long.' : 'too short.'));
    }
}

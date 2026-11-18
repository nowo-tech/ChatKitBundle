<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use RuntimeException;

/**
 * 1.36: a write unit of work was refused by its `Idempotency-Key` claim (another request used the key first). Nothing
 * was written; the caller answers with what that request created.
 *
 * @internal
 */
final class ChatIdempotencyConflict extends RuntimeException
{
    /**
     * Builds the exception.
     */
    public function __construct()
    {
        parent::__construct('The Idempotency-Key was used by another request.');
    }
}

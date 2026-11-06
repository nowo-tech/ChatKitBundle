<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Http;

use RuntimeException;
use Throwable;

/**
 * An API refusal: the message is the error code (`not_found`, `read_only` …), the code the HTTP status.
 * ChatApiExceptionListener renders it as `{ok: false, error: <code>}`, plus `message` when it carries a reason for the
 * person (1.21).
 */
final class ChatApiException extends RuntimeException
{
    /**
     * @param string $message Error code
     * @param int $code HTTP status
     * @param Throwable|null $previous Cause
     * @param string $reason Text for the person; empty = none
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, public readonly string $reason = '')
    {
        parent::__construct($message, $code, $previous);
    }
}

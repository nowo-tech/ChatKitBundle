<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Http;

/**
 * An API refusal: the message is the error code (`not_found`, `read_only` …), the code the HTTP status.
 * ChatApiExceptionListener renders it as `{ok: false, error: <code>}`.
 */
final class ChatApiException extends \RuntimeException
{
}

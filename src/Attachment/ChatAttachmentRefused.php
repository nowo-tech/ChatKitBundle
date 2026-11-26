<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use InvalidArgumentException;
use Throwable;

/**
 * 2.7 (spec 259): an attachment request refused by the rules, before anything is written. `$error` is the stable error
 * code the dock and the client API answer (`{ok: false, error: <code>}`) and `$status` its HTTP status.
 */
final class ChatAttachmentRefused extends InvalidArgumentException
{
    public const string EMPTY_FILE           = 'empty_file';
    public const string INVALID_FILE         = 'invalid_file';
    public const string INVALID_FILE_NAME    = 'invalid_file_name';
    public const string FILE_TOO_LARGE       = 'file_too_large';
    public const string TYPE_NOT_ALLOWED     = 'file_type_not_allowed';
    public const string TYPE_MISMATCH        = 'type_mismatch';
    public const string TOO_MANY_PENDING     = 'too_many_pending';
    public const string TOO_MANY_ATTACHMENTS = 'too_many_attachments';
    public const string NOT_READY            = 'attachment_not_ready';
    public const string INVALID              = 'attachment_invalid';
    public const string IDEMPOTENCY_MISMATCH = 'idempotency_mismatch';
    public const string STORAGE_UNAVAILABLE  = 'storage_unavailable';
    public const string READ_ONLY            = 'read_only';
    public const string RATE_LIMITED         = 'rate_limited';
    public const string CONTENT_ENCODING     = 'content_encoding_refused';

    /** Code => HTTP status. */
    public const array STATUS = [
        self::EMPTY_FILE           => 400,
        self::INVALID_FILE         => 400,
        self::INVALID_FILE_NAME    => 400,
        self::CONTENT_ENCODING     => 400,
        self::READ_ONLY            => 403,
        self::NOT_READY            => 409,
        self::FILE_TOO_LARGE       => 413,
        self::TYPE_NOT_ALLOWED     => 415,
        self::TYPE_MISMATCH        => 415,
        self::TOO_MANY_PENDING     => 422,
        self::TOO_MANY_ATTACHMENTS => 422,
        self::INVALID              => 422,
        self::IDEMPOTENCY_MISMATCH => 422,
        self::RATE_LIMITED         => 429,
        self::STORAGE_UNAVAILABLE  => 503,
    ];

    public readonly int $status;

    /**
     * @param string $error One of the constants
     */
    public function __construct(
        public readonly string $error,
        ?Throwable $previous = null,
    ) {
        parent::__construct($error, 0, $previous);
        $this->status = self::STATUS[$error] ?? 422;
    }
}

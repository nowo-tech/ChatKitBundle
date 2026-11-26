<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment\Message;

/**
 * 2.7 (spec 259): Messenger message: scan one attachment (its id only; the handler re-reads the row). Route it to an
 * asynchronous transport: the scan must never run in a web request. Replays are safe (the claim is a compare-and-set).
 */
final readonly class ScanChatAttachment
{
    /**
     * @param int $attachmentId The row id
     */
    public function __construct(
        public int $attachmentId,
    ) {
    }
}

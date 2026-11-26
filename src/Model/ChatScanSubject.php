<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 2.7 (spec 259): one quarantined file for the host's scanner (ChatAttachmentScannerInterface). Never the file name:
 * names are user content and the scanner does not need them.
 */
final readonly class ChatScanSubject
{
    /**
     * @param string $attachmentUuid The attachment's public uuid
     * @param string $conversationUuid Its conversation
     * @param string $mediaType The type ChatKit sniffed and allow-listed
     * @param int $size Bytes
     * @param string $sha256 SHA-256 of the bytes (lets a host cache verdicts by content)
     * @param ChatFileStream $stream The bytes, bounded to `$size`
     * @param int $timeoutSeconds `attachments.scan.timeout_seconds`: answer (or throw) within it
     * @param int $attempt This attempt's number (1 for the first)
     */
    public function __construct(
        public string $attachmentUuid,
        public string $conversationUuid,
        public string $mediaType,
        public int $size,
        public string $sha256,
        public ChatFileStream $stream,
        public int $timeoutSeconds,
        public int $attempt,
    ) {
    }
}

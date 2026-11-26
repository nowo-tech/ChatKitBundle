<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

/**
 * 2.7 (spec 259): the scan of a file ended (clean, blocked or failed), dispatched after the verdict committed. The
 * signature is for the audit and moderators only; never show it to the uploader or to readers.
 */
final readonly class ChatAttachmentScannedEvent
{
    /**
     * @param string $attachmentUuid The attachment
     * @param string $conversationUuid Its conversation
     * @param string $state The state the verdict left: `clean`, `blocked` or `failed`
     * @param string $outcome The scanner's outcome (`clean`, `infected`, `unscannable`, `retry`)
     * @param string $engine The engine that answered ('' when it never did)
     * @param string|null $signature What matched, when anything did
     * @param int $attempts Attempts made
     */
    public function __construct(
        public string $attachmentUuid,
        public string $conversationUuid,
        public string $state,
        public string $outcome,
        public string $engine,
        public ?string $signature,
        public int $attempts,
    ) {
    }
}

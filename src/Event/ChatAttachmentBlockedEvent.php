<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Nowo\ChatKitBundle\Contract\ChatAttachmentsInterface;

/**
 * 2.7 (spec 259): a file was blocked, by its scan or by the host ({@see ChatAttachmentsInterface::block()}),
 * dispatched after the commit. Ids only.
 */
final readonly class ChatAttachmentBlockedEvent
{
    /** Blocked by the scanner. */
    public const string BY_SCAN = 'scan';

    /** Blocked by the host (a moderator, a re-scan). */
    public const string BY_HOST = 'host';

    /**
     * @param string $attachmentUuid The attachment
     * @param string $conversationUuid Its conversation
     * @param string|null $messageUuid The message it was sent with (null for a draft)
     * @param string $by {@see self::BY_SCAN} or {@see self::BY_HOST}
     */
    public function __construct(
        public string $attachmentUuid,
        public string $conversationUuid,
        public ?string $messageUuid,
        public string $by,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

/**
 * 2.7 (spec 259): a reader downloaded a file, dispatched only with `attachments.audit_downloads: true`. Ids only.
 */
final readonly class ChatAttachmentDownloadedEvent
{
    /**
     * @param string $attachmentUuid The attachment
     * @param string $conversationUuid Its conversation
     * @param string $readerPublicId Who downloaded it
     * @param bool $api Through the client API (else the web dock)
     */
    public function __construct(
        public string $attachmentUuid,
        public string $conversationUuid,
        public string $readerPublicId,
        public bool $api,
    ) {
    }
}

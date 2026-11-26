<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

/**
 * 2.7 (spec 259): a file was stored as a draft, dispatched after the commit. Ids and scalars only: never the file name
 * (audit trails may store it as is).
 */
final readonly class ChatAttachmentUploadedEvent
{
    /**
     * @param string $attachmentUuid The attachment
     * @param string $conversationUuid Its conversation
     * @param string $uploaderPublicId Who uploaded it
     * @param string $mediaType Its allow-listed media type
     * @param int $size Bytes
     */
    public function __construct(
        public string $attachmentUuid,
        public string $conversationUuid,
        public string $uploaderPublicId,
        public string $mediaType,
        public int $size,
    ) {
    }
}

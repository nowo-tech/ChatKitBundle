<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

/**
 * 2.7 (spec 259): a person removed a file (a draft, or a file of a sent message), dispatched after the commit. Files
 * that go with a deleted, purged or erased message get no event of their own. Ids only.
 */
final readonly class ChatAttachmentRemovedEvent
{
    /**
     * @param string $attachmentUuid The attachment
     * @param string $conversationUuid Its conversation
     * @param string|null $messageUuid The message it was sent with (null for a draft)
     * @param string $actorPublicId Who removed it
     */
    public function __construct(
        public string $attachmentUuid,
        public string $conversationUuid,
        public ?string $messageUuid,
        public string $actorPublicId,
    ) {
    }
}

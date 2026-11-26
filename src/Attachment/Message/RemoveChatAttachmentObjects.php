<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment\Message;

/**
 * 2.7 (spec 259): Messenger message: remove the StorageKit objects of deleted or blocked attachments (ids only), after
 * the commit that marked them. Idempotent; `nowo:chat:attachments:sweep` removes whatever a lost message left.
 */
final readonly class RemoveChatAttachmentObjects
{
    /**
     * @param list<int> $attachmentIds Row ids
     */
    public function __construct(
        public array $attachmentIds,
    ) {
    }
}

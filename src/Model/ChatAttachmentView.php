<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Enum\ChatAttachmentKind;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;

/**
 * 2.7 (spec 259): a read-only view of a chat attachment for hosts and clients (REQ-ARCH-001: no Doctrine type). The
 * name is user content: escape it wherever it is shown, and never use it in a URL path.
 */
final readonly class ChatAttachmentView
{
    /**
     * @param string $uuid Public id
     * @param string $conversationUuid Its conversation
     * @param string|null $messageUuid The message it was sent with; null while it is a draft
     * @param string $name Sanitized file name
     * @param string $extension Lower-case extension ('' when none)
     * @param string $mediaType The allow-listed media type it is served with
     * @param ChatAttachmentKind $kind What it is
     * @param int $size Bytes
     * @param ChatAttachmentState $state Where it is in its life (readers only ever see Clean)
     * @param int $position Order in its message (0 for a draft)
     * @param DateTimeImmutable $createdAt Upload time
     */
    public function __construct(
        public string $uuid,
        public string $conversationUuid,
        public ?string $messageUuid,
        public string $name,
        public string $extension,
        public string $mediaType,
        public ChatAttachmentKind $kind,
        public int $size,
        public ChatAttachmentState $state,
        public int $position,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * The JSON shape the dock and the client API share (no URL: those are per reader and short-lived).
     *
     * @return array{uuid: string, name: string, extension: string, mediaType: string, kind: string, size: int, state: string}
     */
    public function toArray(): array
    {
        return [
            'uuid'      => $this->uuid,
            'name'      => $this->name,
            'extension' => $this->extension,
            'mediaType' => $this->mediaType,
            'kind'      => $this->kind->value,
            'size'      => $this->size,
            'state'     => $this->state->value,
        ];
    }
}

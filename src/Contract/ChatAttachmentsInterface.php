<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Attachment\ChatAttachmentRefused;
use Nowo\ChatKitBundle\Model\ChatAttachmentUrl;
use Nowo\ChatKitBundle\Model\ChatAttachmentView;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;

/**
 * 2.7 (spec 259 block B): chat attachments by public uuid, for DDD hosts and the bundle's own routes. Registered only
 * with `attachments.enabled` (nowo-tech/storage-kit-bundle ^2.2, symfony/messenger and a scanner).
 *
 * A file is uploaded as a draft that only its uploader sees, scanned (mandatory, fail-closed), and sent with a message
 * once clean. Unknown, foreign and forbidden uuids are one answer (ChatNotFoundException); ids are compared byte for
 * byte. Every refusal of the rules is a {@see ChatAttachmentRefused} with a stable code.
 */
interface ChatAttachmentsInterface
{
    /**
     * Stores a file as a draft of `$uploader` in the conversation and queues its scan. The bytes travel before any
     * lock is taken; the row is then written in one short unit of work.
     *
     * @param string $path A local regular file the caller owns (an uploaded temporary file); it is read, never moved
     * @param string $name The client's file name (sanitized; never trusted for the type)
     * @param string|null $idempotencyKey Makes a retry answer the first upload (`[A-Za-z0-9_.:-]{1,80}`)
     *
     * @throws ChatAttachmentRefused `empty_file`, `invalid_file_name`, `file_too_large`, `file_type_not_allowed`, `type_mismatch`, `too_many_pending`, `idempotency_mismatch`, `storage_unavailable`, `read_only`
     * @throws ChatNotFoundException When the conversation is unknown or not readable by the uploader
     */
    public function upload(ChatUserInterface $uploader, string $conversationUuid, string $path, string $name, ?string $idempotencyKey = null): ChatAttachmentView;

    /**
     * The uploader's drafts in a conversation (any state but deleted), oldest first.
     *
     * @throws ChatNotFoundException When the conversation is unknown or not readable
     *
     * @return list<ChatAttachmentView>
     */
    public function drafts(ChatUserInterface $uploader, string $conversationUuid): array;

    /**
     * The clean files of a message the viewer may read, in order.
     *
     * @throws ChatNotFoundException When the conversation or the message is unknown or not readable
     *
     * @return list<ChatAttachmentView>
     */
    public function attachmentsOf(ChatUserInterface $viewer, string $conversationUuid, string $messageUuid): array;

    /**
     * One attachment as the viewer may see it: a clean file of a message they may read, or one of their own drafts.
     *
     * @throws ChatNotFoundException Otherwise
     */
    public function find(ChatUserInterface $viewer, string $attachmentUuid): ChatAttachmentView;

    /**
     * A download URL of the original, signed for this viewer (and for the client API token and context when given).
     *
     * @throws ChatNotFoundException When the viewer may not read the file now
     */
    public function downloadUrl(ChatUserInterface $viewer, string $attachmentUuid, ?string $tokenId = null, ?string $contextKey = null): ChatAttachmentUrl;

    /**
     * Removes a draft (its uploader), or a sent file (who may delete its message). Idempotent.
     *
     * @throws ChatNotFoundException When the viewer may not see or remove it
     */
    public function remove(ChatUserInterface $actor, string $attachmentUuid): void;

    /**
     * Sends a message with files (the text may be empty when at least one file is attached). Every file must be a
     * clean draft of the author in this conversation, checked under the conversation's lock: one that is not refuses the
     * whole send and nothing is written.
     *
     * @param list<string> $attachmentUuids Draft uuids, in the order they appear in the message
     * @param string|null $idempotencyKey Makes a retry answer the first message
     *
     * @throws ChatAttachmentRefused `attachment_not_ready`, `attachment_invalid`, `too_many_attachments`
     * @throws ChatNotFoundException When the conversation is unknown or not readable
     */
    public function send(ChatUserInterface $author, string $conversationUuid, string $body, array $attachmentUuids, ?string $idempotencyKey = null): ChatMessageView;

    /**
     * For a host re-scan or a moderator: hides a clean file from everyone (CAS clean → blocked) and removes its
     * objects after the commit. Idempotent.
     *
     * @return bool True when this call blocked it
     */
    public function block(string $attachmentUuid): bool;
}

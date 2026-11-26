<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Enum\ChatAttachmentKind;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;
use Nowo\ChatKitBundle\Model\ChatAttachmentView;
use Nowo\ChatKitBundle\Repository\ChatAttachmentRepository;

use function array_map;
use function array_values;

/**
 * 2.7 (spec 259): the files of messages as readers see them: only `clean` files of messages that are not deleted, in
 * one query per page (SC-003). Viewer-neutral: whoever may read a message may see its files (the scan ran before the
 * send). Stateless.
 *
 * @phpstan-import-type AttachmentRow from ChatAttachmentRepository
 */
final readonly class ChatAttachmentViews
{
    /**
     * @param ChatAttachmentRepository $rows The attachment rows
     */
    public function __construct(
        private ChatAttachmentRepository $rows,
    ) {
    }

    /**
     * The files of these messages (deleted messages have none).
     *
     * @param iterable<ChatMessage> $messages
     *
     * @return array<int, list<ChatAttachmentView>> message id => files in order
     */
    public function forMessages(iterable $messages): array
    {
        $ids   = [];
        $uuids = [];
        foreach ($messages as $message) {
            $id = $message->getId();
            if ($id !== null && !$message->isDeleted()) {
                $ids[]      = $id;
                $uuids[$id] = $message->getUuid();
            }
        }
        $views = [];
        foreach ($this->rows->forMessages($ids) as $messageId => $rows) {
            $views[$messageId] = array_values(array_map(static fn (array $row): ChatAttachmentView => self::view($row, $uuids[$messageId] ?? null), $rows));
        }

        return $views;
    }

    /**
     * The JSON stubs of these messages' files (no URL), for the dock and the realtime `full` payload.
     *
     * @param iterable<ChatMessage> $messages
     *
     * @return array<int, list<array<string, mixed>>> message id => files
     */
    public function stubs(iterable $messages): array
    {
        return array_map(static fn (array $views): array => array_values(array_map(static fn (ChatAttachmentView $view): array => $view->toArray(), $views)), $this->forMessages($messages));
    }

    /**
     * The view of a row.
     *
     * @param AttachmentRow $row
     */
    public static function view(array $row, ?string $messageUuid): ChatAttachmentView
    {
        return new ChatAttachmentView(
            $row['uuid'],
            $row['conversation_uuid'],
            $messageUuid,
            $row['name'],
            $row['extension'],
            $row['media_type'],
            ChatAttachmentKind::tryFrom($row['kind']) ?? ChatAttachmentKind::Other,
            $row['size_bytes'],
            ChatAttachmentState::tryFrom($row['state']) ?? ChatAttachmentState::Deleted,
            $row['position'],
            new DateTimeImmutable($row['created_at']),
        );
    }
}

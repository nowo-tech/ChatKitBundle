<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Attachment\Message\RemoveChatAttachmentObjects;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatAttachmentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function count;

/**
 * 2.7 (spec 259 §9, §16): files go with their message. A message deleted, a conversation purged and a person erased call
 * {@see self::forMessages()} / {@see self::forConversation()} / {@see self::forUploader()} **inside their unit of work**,
 * so the rows are `deleted` exactly when the messages go; the retention purge calls {@see self::forMessages()} in its own
 * statement just before each batch's message delete (audit ZCO-I3: a crash in between only marks files of messages
 * past the cutoff, deleted by the next run). Each then calls {@see self::afterCommit()} to queue the removal of the
 * StorageKit objects
 * ({@see RemoveChatAttachmentObjects}). A lost message leaves nothing behind for long: the sweep removes the
 * objects of every `deleted` row.
 *
 * No StorageKit type here (the services that call it exist whatever `attachments.enabled` says; this one only with it).
 * Stateless.
 *
 * @internal
 */
final readonly class ChatAttachmentCleanup
{
    /** Rows read at a time by the export. */
    public const int EXPORT_BATCH = 500;

    /**
     * @param ChatAttachmentRepository $rows The attachment rows
     * @param MessageBusInterface|null $bus Queues the object removal; null leaves it to the sweep
     * @param LoggerInterface|null $logger Logs a failed dispatch (the sweep catches up)
     */
    public function __construct(
        private ChatAttachmentRepository $rows,
        private ?MessageBusInterface $bus = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Inside the unit of work: the files of these messages are `deleted`.
     *
     * @param list<int> $messageIds
     *
     * @return bool Whether any row changed (call {@see self::afterCommit()} then)
     */
    public function forMessages(array $messageIds, DateTimeImmutable $now): bool
    {
        return $this->rows->markDeletedForMessages($messageIds, $now) > 0;
    }

    /**
     * Inside the unit of work: every file of the conversation (drafts included) is `deleted`.
     */
    public function forConversation(int $conversationId, DateTimeImmutable $now): bool
    {
        return $this->rows->markDeletedForConversation($conversationId, $now) > 0;
    }

    /**
     * Inside the erasure's unit of work: every file the person uploaded is `deleted` and forgets them.
     */
    public function forUploader(ChatUserInterface $user, DateTimeImmutable $now): bool
    {
        $key = ChatUserReferences::key($user);

        return $key !== null && $this->rows->eraseUploader($key, $now) > 0;
    }

    /**
     * GDPR export: the person's uploads (any state but deleted), oldest first. The bytes come from StorageKit's own
     * export (`StoredFilesPersonalDataInterface`); `storage_uuid` joins the two.
     *
     * @return list<array{uuid: string, name: string, media_type: string, size_bytes: int, conversation_uuid: string, message_uuid: string|null, state: string, storage_uuid: string, created_at: string}>
     */
    public function export(ChatUserInterface $user): array
    {
        $key = ChatUserReferences::key($user);
        $all = [];
        for ($after = 0; $key !== null;) {
            $page = $this->rows->exportPage($key, $after, self::EXPORT_BATCH);
            foreach ($page as $row) {
                $after = $row['id'];
                unset($row['id']);
                $all[] = $row;
            }
            if (count($page) < self::EXPORT_BATCH) {
                break;
            }
        }

        return $all;
    }

    /**
     * After the commit: queues the removal of the objects of `deleted` and `blocked` rows (all of them, or these ids).
     * Never throws: a failed dispatch is logged and the sweep removes them.
     *
     * @param list<int>|null $ids The rows; null for whatever is waiting
     */
    public function afterCommit(?array $ids = null): void
    {
        if (!$this->bus instanceof MessageBusInterface || $ids === []) {
            return;
        }
        try {
            $ids ??= array_map(static fn (array $row): int => $row['id'], $this->rows->objectsToRemove(500));
            if ($ids !== []) {
                $this->bus->dispatch(new RemoveChatAttachmentObjects($ids));
            }
        } catch (Throwable $exception) {
            $this->logger?->warning('ChatKit: the removal of attachment objects could not be queued; nowo:chat:attachments:sweep removes them.', ['exception' => $exception::class]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use Nowo\ChatKitBundle\Attachment\Message\RemoveChatAttachmentObjects;
use Nowo\ChatKitBundle\Repository\ChatAttachmentRepository;
use Nowo\StorageKitBundle\Lifecycle\FileRemoverInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * 2.7 (spec 259): removes the StorageKit objects of `deleted` and `blocked` attachments, outside any transaction (bucket
 * calls), then drops the `deleted` rows. A bucket that refuses leaves the file expired (no longer served, deleted by
 * StorageKit's purge). Handles {@see RemoveChatAttachmentObjects}; the sweep calls it too. Idempotent and stateless.
 *
 * @internal
 */
#[AsMessageHandler]
final readonly class ChatAttachmentObjects
{
    /** StorageKit context type of the chat files. */
    public const string CONTEXT = 'chat';

    /**
     * @param ChatAttachmentRepository $rows The attachment rows
     * @param FileRemoverInterface $remover StorageKit's remover
     * @param ClockInterface $clock Removal time of blocked files' objects
     * @param LoggerInterface|null $logger Logs a bucket refusal (no file name)
     */
    public function __construct(
        private ChatAttachmentRepository $rows,
        private FileRemoverInterface $remover,
        private ClockInterface $clock = new Clock(),
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Messenger entry point.
     */
    public function __invoke(RemoveChatAttachmentObjects $message): void
    {
        $this->remove($message->attachmentIds);
    }

    /**
     * Removes the objects of these rows (or of up to `$limit` waiting rows when null).
     *
     * @param list<int>|null $ids
     *
     * @return int Rows handled
     */
    public function remove(?array $ids, int $limit = 200): int
    {
        $handled = 0;
        foreach ($this->rows->objectsToRemove($limit, $ids) as $row) {
            try {
                // @igor-ignore - a StorageKit call (deletes a bucket object), not a change of the injected service's state.
                $this->remover->remove($row['storage_uuid'], self::CONTEXT, $row['conversation_uuid']);
            } catch (Throwable $exception) {
                $this->logger?->warning('ChatKit: an attachment object could not be removed; it is expired for StorageKit\'s purge.', ['attachment' => $row['uuid'], 'exception' => $exception::class]);
                $this->remover->expire($row['storage_uuid'], self::CONTEXT, $row['conversation_uuid']);
            }
            $this->rows->objectsRemoved($row['id'], $this->clock->now());
            ++$handled;
        }

        return $handled;
    }
}

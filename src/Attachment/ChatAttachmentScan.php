<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Attachment\Message\ScanChatAttachment;
use Nowo\ChatKitBundle\Contract\ChatAttachmentScannerInterface;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;
use Nowo\ChatKitBundle\Enum\ChatScanOutcome;
use Nowo\ChatKitBundle\Event\ChatAttachmentBlockedEvent;
use Nowo\ChatKitBundle\Event\ChatAttachmentScannedEvent;
use Nowo\ChatKitBundle\Model\ChatFileStream;
use Nowo\ChatKitBundle\Model\ChatScanSubject;
use Nowo\ChatKitBundle\Model\ChatScanVerdict;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatAttachmentRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\StorageKitBundle\Download\FileReaderInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

use function bin2hex;
use function in_array;
use function random_bytes;

/**
 * 2.7 (spec 259 §9, QB-259-2): the mandatory scan of one quarantined file, fail-closed.
 *
 * 1. **C1 claim**: a compare-and-set takes the file's lease under a fresh token (one worker per file; a Messenger replay
 *    or a second worker changes nothing).
 * 2. **Scan**, outside any transaction and lock: StorageKit's bounded read stream goes to the host's scanner, closed in
 *    a `finally`.
 * 3. **C2 verdict**, guarded by the token: clean → `clean`; infected → `blocked`; unscannable → `blocked` (or `clean`
 *    with `scan.unscannable: allow`); retry or an exception → back to `uploaded` with a backoff, `failed` after
 *    `scan.max_attempts`. A verdict whose lease was lost (the file was removed, or the lease expired and another worker
 *    took it) is discarded.
 *
 * After the verdict: ChatAttachmentScannedEvent (and ChatAttachmentBlockedEvent), the objects of a blocked file queued
 * for removal, and a realtime `attachment` event to the uploader only. Stateless; the handler re-reads the row by id.
 *
 * @internal
 */
#[AsMessageHandler]
final readonly class ChatAttachmentScan
{
    /**
     * @param ChatAttachmentRepository $rows The attachment rows
     * @param ChatAttachmentScannerInterface $scanner The host's scanner
     * @param FileReaderInterface $reader StorageKit's server-side reader
     * @param ChatAttachmentOptions $options Lease, attempts, unscannable policy
     * @param ChatAttachmentCleanup $cleanup Queues the removal of a blocked file's objects
     * @param MessageBusInterface|null $bus Schedules the retries (DelayStamp); null leaves them to the sweep
     * @param ChatNotifier|null $notifier Tells the uploader's open clients the new state
     * @param ChatAccess|null $access Loads the uploader for that event
     * @param EventDispatcherInterface|null $events Receives the scan events
     * @param ClockInterface $clock Lease and verdict times
     * @param LoggerInterface|null $logger Logs scanner failures (ids only)
     */
    public function __construct(
        private ChatAttachmentRepository $rows,
        private ChatAttachmentScannerInterface $scanner,
        private FileReaderInterface $reader,
        private ChatAttachmentOptions $options,
        private ChatAttachmentCleanup $cleanup,
        private ?MessageBusInterface $bus = null,
        private ?ChatNotifier $notifier = null,
        private ?ChatAccess $access = null,
        private ?EventDispatcherInterface $events = null,
        private ClockInterface $clock = new Clock(),
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Messenger entry point.
     */
    public function __invoke(ScanChatAttachment $message): void
    {
        $this->scan($message->attachmentId);
    }

    /**
     * Scans one file when it is due.
     *
     * @return ChatAttachmentState|null The state the verdict left (Uploaded for a retry); null when this call did not own the scan or its verdict was discarded
     */
    public function scan(int $id): ?ChatAttachmentState
    {
        $row = $this->rows->findRowById($id);
        if ($row === null || !in_array($row['state'], [ChatAttachmentState::Uploaded->value, ChatAttachmentState::Scanning->value], true)) {
            return null;
        }
        $token = bin2hex(random_bytes(16));
        $now   = $this->clock->now();
        if (!$this->rows->claim($id, $token, $now, $this->later($now, $this->options->leaseSeconds), $this->options->maxAttempts)) {
            return null;
        }
        $attempt = $row['scan_attempts'] + 1;
        $verdict = null;
        $stream  = null;
        try {
            $source  = $this->reader->open($row['storage_uuid'], ChatAttachmentObjects::CONTEXT, $row['conversation_uuid'], 'original');
            $stream  = new ChatFileStream($source->size, $source->read(...), $source->eof(...), $source->close(...));
            $verdict = $this->scanner->scan(new ChatScanSubject($row['uuid'], $row['conversation_uuid'], $row['media_type'], $row['size_bytes'], $row['sha256'], $stream, $this->options->scanTimeoutSeconds, $attempt));
        } catch (Throwable $exception) {
            // Never fail open: an error is a retry, and the file stays quarantined.
            $this->logger?->warning('ChatKit: the attachment scanner failed; the file stays quarantined and is retried.', ['attachment' => $row['uuid'], 'attempt' => $attempt, 'exception' => $exception::class]);
        } finally {
            $stream?->close();
        }
        $outcome = $verdict instanceof ChatScanVerdict ? $verdict->outcome : ChatScanOutcome::Retry;
        $state   = match ($outcome) {
            ChatScanOutcome::Clean       => ChatAttachmentState::Clean,
            ChatScanOutcome::Infected    => ChatAttachmentState::Blocked,
            ChatScanOutcome::Unscannable => $this->options->allowUnscannable ? ChatAttachmentState::Clean : ChatAttachmentState::Blocked,
            ChatScanOutcome::Retry       => $attempt >= $this->options->maxAttempts ? ChatAttachmentState::Failed : null,
        };
        $now = $this->clock->now();
        if ($state === null) {
            $delay = $this->options->backoff($attempt);
            if (!$this->rows->release($id, $token, $this->later($now, $delay))) {
                return null;
            }
            $this->retryLater($id, $delay);

            return ChatAttachmentState::Uploaded;
        }
        if (!$this->rows->verdict($id, $token, $state, $verdict instanceof ChatScanVerdict ? $verdict->engine : '', $verdict?->signature, $now)) {
            return null;
        }
        $this->events?->dispatch(new ChatAttachmentScannedEvent($row['uuid'], $row['conversation_uuid'], $state->value, $outcome->value, $verdict instanceof ChatScanVerdict ? $verdict->engine : '', $verdict?->signature, $attempt));
        if ($state === ChatAttachmentState::Blocked) {
            $this->events?->dispatch(new ChatAttachmentBlockedEvent($row['uuid'], $row['conversation_uuid'], null, ChatAttachmentBlockedEvent::BY_SCAN));
            $this->cleanup->afterCommit([$id]);
        }
        $this->tellUploader($row['uploader_id'], $row['conversation_uuid'], $row['uuid'], $state);

        return $state;
    }

    /** A time `$seconds` after `$now` (a new immutable value). */
    private function later(DateTimeImmutable $now, int $seconds): DateTimeImmutable
    {
        // Audit ZCO-I5: the clock's own timezone (the DATETIME columns store the wall time of that zone).
        return $now->setTimestamp($now->getTimestamp() + $seconds);
    }

    private function retryLater(int $id, int $delay): void
    {
        try {
            $this->bus?->dispatch(new ScanChatAttachment($id), [new DelayStamp($delay * 1000)]);
        } catch (Throwable $exception) {
            $this->logger?->warning('ChatKit: an attachment scan retry could not be queued; nowo:chat:attachments:sweep queues it.', ['exception' => $exception::class]);
        }
    }

    private function tellUploader(int|string|null $uploaderKey, string $conversationUuid, string $attachmentUuid, ChatAttachmentState $state): void
    {
        if ($uploaderKey === null || !$this->notifier instanceof ChatNotifier || !$this->access instanceof ChatAccess) {
            return;
        }
        $user = $this->access->usersByIds([$uploaderKey])[(string) $uploaderKey] ?? null;
        if ($user instanceof ChatUserInterface) {
            $this->notifier->attachment($user, $conversationUuid, $attachmentUuid, $state->value);
        }
    }
}

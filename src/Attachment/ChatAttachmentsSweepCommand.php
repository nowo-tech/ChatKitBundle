<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Attachment;

use DateInterval;
use DateTimeImmutable;
use Nowo\ChatKitBundle\Attachment\Message\ScanChatAttachment;
use Nowo\ChatKitBundle\Event\ChatAttachmentScanOverdueEvent;
use Nowo\ChatKitBundle\Repository\ChatAttachmentRepository;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function count;
use function max;
use function sprintf;

/**
 * 2.7 (spec 259 §9): `nowo:chat:attachments:sweep`, run from cron (every minute or two). Re-run safe; each step is its
 * own short statement or batch:
 *
 * 1. files whose scans are exhausted become `failed` (fail-closed);
 * 2. files due for a scan (never picked up, a retry due, an expired lease) are queued again;
 * 3. drafts older than `pending_ttl_hours` (and rows a purge detached) are deleted, 200 at a time;
 * 4. the StorageKit objects of deleted and blocked files are removed;
 * 5. ChatAttachmentScanOverdueEvent when files wait longer than `scan.alert_after_seconds` or ended failed (QB-259-2).
 *
 * A lock conflict exits with code 75 (EX_TEMPFAIL, `CONFLICT_001`): nothing half-done stays, run it again.
 */
#[AsCommand(name: 'nowo:chat:attachments:sweep', description: 'Queue due attachment scans, purge stale drafts and remove the objects of deleted files.')]
final class ChatAttachmentsSweepCommand extends Command
{
    /** Rows per batch. */
    public const int BATCH = 200;

    /** Exit code of a lock conflict (EX_TEMPFAIL). */
    public const int CONFLICT = 75;

    /**
     * @param ChatAttachmentRepository $rows The attachment rows
     * @param ChatAttachmentOptions $options TTL, attempts, alert threshold
     * @param ChatAttachmentObjects $objects Removes the objects
     * @param MessageBusInterface|null $bus Queues the scans
     * @param EventDispatcherInterface|null $events Receives the scan alert
     * @param ClockInterface $clock Now
     * @param LoggerInterface|null $logger Logs a conflict
     */
    public function __construct(
        private readonly ChatAttachmentRepository $rows,
        private readonly ChatAttachmentOptions $options,
        private readonly ChatAttachmentObjects $objects,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ClockInterface $clock = new Clock(),
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Stop each step after this many batches', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $max = max(1, (int) $input->getOption('max-batches'));
        $now = $this->clock->now();
        try {
            $failed = count($this->rows->failExhausted($now, $this->options->maxAttempts));
            $queued = 0;
            foreach ($this->rows->dueForScan($now, $this->options->maxAttempts, self::BATCH * $max) as $id) {
                $this->bus?->dispatch(new ScanChatAttachment($id));
                ++$queued;
            }
            $cutoff = $now->sub(new DateInterval('PT' . $this->options->pendingTtlHours . 'H'));
            $stale  = 0;
            for ($batch = 0; $batch < $max; ++$batch) {
                $ids = $this->rows->staleIds($cutoff, self::BATCH);
                $stale += $this->rows->deleteStale($ids, $cutoff, $now);
                if (count($ids) < self::BATCH) {
                    break;
                }
            }
            $removed = 0;
            for ($batch = 0; $batch < $max; ++$batch) {
                // @igor-ignore - removes bucket objects of deleted rows; the service keeps no state.
                $handled = $this->objects->remove(null, self::BATCH);
                $removed += $handled;
                if ($handled < self::BATCH) {
                    break;
                }
            }
            $this->alert($now);
        } catch (Throwable $conflict) {
            // A lock wait or deadlock (MySQL 1205 / 1213, PostgreSQL 55P03 / 40P01, …): transaction-doctrine's classifier.
            if (!$conflict instanceof TransientStorageFailure && !(new TransientFailureClassifier())->isTransient($conflict)) {
                throw $conflict;
            }
            $this->logger?->warning('ChatKit: the attachment sweep met a lock conflict (CONFLICT_001); run it again.', ['exception' => $conflict::class]);
            (new SymfonyStyle($input, $output))->warning('CONFLICT_001: a lock conflict stopped the sweep; nothing half-done was left. Run it again.');

            return self::CONFLICT;
        }
        (new SymfonyStyle($input, $output))->success(sprintf('Attachments: %d scan(s) queued, %d failed, %d stale draft(s) removed, %d object set(s) removed.', $queued, $failed, $stale, $removed));

        return Command::SUCCESS;
    }

    private function alert(DateTimeImmutable $now): void
    {
        $since                       = $now->sub(new DateInterval('PT' . $this->options->alertAfterSeconds . 'S'));
        [$waiting, $oldest, $failed] = $this->rows->overdue($since);
        if ($waiting === 0 && $failed === 0) {
            return;
        }
        $age = $oldest === null ? 0 : max(0, $now->getTimestamp() - (new DateTimeImmutable($oldest))->getTimestamp());
        $this->events?->dispatch(new ChatAttachmentScanOverdueEvent($waiting, $age, $failed));
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Command;

use Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationLinkRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatReadMarkRepository;
use Nowo\ChatKitBundle\Support\ChatRetention;
use Nowo\ChatKitBundle\Support\InvalidRetentionConfigurationException;
use Nowo\ChatKitBundle\Support\RetentionUnavailableException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function count;
use function sprintf;

use const DATE_ATOM;

/**
 * Deletes messages (and their reactions and link index rows) older than `retention_days`, and (1.19) pinned links not
 * changed within that window, in bounded batches. Schedule it daily.
 */
#[AsCommand(name: 'nowo:chat:purge', description: 'Delete chat messages older than the retention window')]
final class ChatPurgeCommand extends Command
{
    public const int MIN_DAYS = ChatRetention::MIN_DAYS;
    private const int BATCH   = 1000;

    /**
     * Builds the command from the message repository and the configured retention.
     *
     * @param ChatMessageRepository $messages Deletes the old messages in batches
     * @param int $retentionDays Configured retention window in days (never less than MIN_DAYS; the host's provider wins when it answers)
     * @param ClockInterface $clock Clock the cutoff is computed from
     * @param LoggerInterface $logger Start / end / failure of a purge run (counts only, never message content)
     * @param ChatConversationLinkRepository|null $links 1.19: pinned links not changed within the window go too (audit FZ-L3)
     * @param RetentionPolicyProviderInterface|null $retention 1.31: the host's retention, asked again on every run (null: `$retentionDays` only)
     * @param ChatReadMarkRepository|null $readMarks 1.35: read times older than the window go too (message info)
     */
    public function __construct(
        private readonly ChatMessageRepository $messages,
        private readonly int $retentionDays = 365,
        private readonly ClockInterface $clock = new Clock(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ChatConversationLinkRepository $links = null,
        private readonly ?RetentionPolicyProviderInterface $retention = null,
        private readonly ?ChatReadMarkRepository $readMarks = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Stop after this many batches (one run stays bounded)', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted   = 0;
        $links     = 0;
        $readMarks = 0;
        $max       = max(1, (int) $input->getOption('max-batches'));
        try {
            $days   = (new ChatRetention($this->retentionDays, $this->retention, $this->logger))->days();
            $now    = $this->clock->now();
            $cutoff = $now->modify(sprintf('-%d days', $days));
            // Defence in depth: whatever the arithmetic gave, never delete anything newer than the minimum window.
            if ($cutoff > $now->modify(sprintf('-%d days', self::MIN_DAYS))) {
                $this->logger->error('Chat purge aborted: the cutoff is inside the minimum retention window.', ['retention_days' => $days, 'cutoff' => $cutoff->format(DATE_ATOM)]);

                return Command::FAILURE;
            }
            $this->logger->info('Chat purge started.', ['retention_days' => $days, 'cutoff' => $cutoff->format(DATE_ATOM), 'max_batches' => $max]);
            for ($batch = 0; $batch < $max; ++$batch) {
                $count = $this->messages->purgeOlderThan($cutoff, self::BATCH);
                $deleted += $count;
                if ($count < self::BATCH) {
                    break;
                }
            }
            for ($batch = 0; $this->links instanceof ChatConversationLinkRepository && $batch < $max; ++$batch) {
                $count = $this->links->purgeOlderThan($cutoff, self::BATCH);
                $links += $count;
                if ($count < self::BATCH) {
                    break;
                }
            }
            // 1.35: read times (personal data) older than the window, in small units.
            for ($batch = 0; $this->readMarks instanceof ChatReadMarkRepository && $batch < $max; ++$batch) {
                $ids = $this->readMarks->idsBefore($cutoff);
                $this->readMarks->deleteIds($ids);
                $readMarks += count($ids);
                if (count($ids) < ChatReadMarkRepository::PURGE_BATCH) {
                    break;
                }
            }
        } catch (InvalidRetentionConfigurationException $exception) {
            // Fail closed (1.31.1): nothing is deleted when the configured retention is not a positive number.
            $this->logger->error('Chat purge aborted: the configured retention_days is invalid (0 or negative).', ['configured_days' => $exception->days, 'minimum_days' => self::MIN_DAYS]);

            return Command::FAILURE;
        } catch (RetentionUnavailableException $exception) {
            // Fail closed (1.32): an invalid or unreadable runtime setting (`runtime.retention_days`) deletes nothing and
            // never falls back to the configured value, a default or "keep forever".
            $this->logger->error('Chat purge aborted: the runtime retention setting is invalid or unavailable.', ['setting' => $exception->setting, 'reason' => $exception->getMessage()]);
            (new SymfonyStyle($input, $output))->error($exception->getMessage());

            return Command::FAILURE;
        } catch (Throwable $exception) {
            $this->logger->error('Chat purge failed.', ['deleted' => $deleted, 'exception' => $exception::class, 'message' => $exception->getMessage()]);

            throw $exception;
        }
        $this->logger->info('Chat purge finished.', ['deleted' => $deleted, 'links' => $links, 'read_marks' => $readMarks, 'retention_days' => $days]);
        (new SymfonyStyle($input, $output))->success(sprintf('Deleted %d chat message(s) and %d pinned link(s) older than %d days.', $deleted, $links, $days));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Command;

use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
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

use function sprintf;

use const DATE_ATOM;

/**
 * Deletes messages (and their reactions) older than `retention_days`, in bounded batches. Schedule it daily.
 */
#[AsCommand(name: 'nowo:chat:purge', description: 'Delete chat messages older than the retention window')]
final class ChatPurgeCommand extends Command
{
    public const int MIN_DAYS = 30;
    private const int BATCH   = 1000;

    /**
     * Builds the command from the message repository and the configured retention.
     *
     * @param ChatMessageRepository $messages Deletes the old messages in batches
     * @param int $retentionDays Retention window in days (never less than MIN_DAYS)
     * @param ClockInterface $clock Clock the cutoff is computed from
     * @param LoggerInterface $logger Start / end / failure of a purge run (counts only, never message content)
     */
    public function __construct(
        private readonly ChatMessageRepository $messages,
        private readonly int $retentionDays = 365,
        private readonly ClockInterface $clock = new Clock(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Stop after this many batches (one run stays bounded)', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days    = max(self::MIN_DAYS, $this->retentionDays);
        $cutoff  = $this->clock->now()->modify(sprintf('-%d days', $days));
        $deleted = 0;
        $max     = max(1, (int) $input->getOption('max-batches'));
        $this->logger->info('Chat purge started.', ['retention_days' => $days, 'cutoff' => $cutoff->format(DATE_ATOM), 'max_batches' => $max]);
        try {
            for ($batch = 0; $batch < $max; ++$batch) {
                $count = $this->messages->purgeOlderThan($cutoff, self::BATCH);
                $deleted += $count;
                if ($count < self::BATCH) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            $this->logger->error('Chat purge failed.', ['deleted' => $deleted, 'exception' => $exception::class, 'message' => $exception->getMessage()]);

            throw $exception;
        }
        $this->logger->info('Chat purge finished.', ['deleted' => $deleted, 'retention_days' => $days]);
        (new SymfonyStyle($input, $output))->success(sprintf('Deleted %d chat message(s) older than %d days.', $deleted, $days));

        return Command::SUCCESS;
    }
}

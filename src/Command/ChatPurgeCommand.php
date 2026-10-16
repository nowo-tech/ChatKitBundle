<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Command;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

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
     */
    public function __construct(
        private readonly ChatMessageRepository $messages,
        private readonly int $retentionDays = 365,
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
        $cutoff  = new DateTimeImmutable(sprintf('-%d days', $days));
        $deleted = 0;
        $max     = max(1, (int) $input->getOption('max-batches'));
        for ($batch = 0; $batch < $max; ++$batch) {
            $count = $this->messages->purgeOlderThan($cutoff, self::BATCH);
            $deleted += $count;
            if ($count < self::BATCH) {
                break;
            }
        }
        (new SymfonyStyle($input, $output))->success(sprintf('Deleted %d chat message(s) older than %d days.', $deleted, $days));

        return Command::SUCCESS;
    }
}

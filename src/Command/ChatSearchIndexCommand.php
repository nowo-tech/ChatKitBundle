<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Command;

use Nowo\ChatKitBundle\Search\ChatSearchIndex;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

/**
 * 1.37: shows, prints or builds the full-text index of message search. Without options it prints the index state and
 * the SQL (paste it into a migration); `--force` runs it; `--drop` removes the index instead. On PostgreSQL the index is
 * built `CONCURRENTLY` (writes go on; the statement cannot run inside a transaction) and an INVALID index left by a
 * failed build is dropped first with `--force`. A lock wait, deadlock or lock timeout stops the command with
 * `CONFLICT_001` (no usable index was built; on PostgreSQL an INVALID index may remain until `--force` runs again; when
 * the build followed the drop of an INVALID index, no index is left at all: REQ-RUNTIME-003 rule 6); other errors are
 * rethrown.
 */
#[AsCommand(name: 'nowo:chat:search-index', description: 'Show, print or build the full-text index of chat message search')]
final class ChatSearchIndexCommand extends Command
{
    /** Exit code of a lock conflict (EX_TEMPFAIL: try again later). */
    public const int CONFLICT = 75;

    /**
     * @param ChatSearchIndex $index The index, its state and its SQL
     */
    public function __construct(private readonly ChatSearchIndex $index)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Run the statement instead of printing it')
            ->addOption('drop', null, InputOption::VALUE_NONE, 'Drop the index instead of building it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->index->supported()) {
            $io->note('This database has no full-text index for chat search: the search uses LIKE. Nothing to do.');

            return Command::SUCCESS;
        }
        $drop  = (bool) $input->getOption('drop');
        $state = $this->index->state();
        $io->writeln(sprintf('Index <info>%s</info> on <info>%s</info>: %s.', $this->index->name(), $this->index->table(), $state));
        $sql = $drop ? $this->index->dropSql() : $this->index->createSql();
        if (!$input->getOption('force')) {
            $io->writeln($sql . ';');

            return Command::SUCCESS;
        }
        if ($drop ? $state === ChatSearchIndex::MISSING : $state === ChatSearchIndex::READY) {
            $io->success($drop ? 'Nothing to drop.' : 'The index is already there.');

            return Command::SUCCESS;
        }

        try {
            if (!$drop && $state === ChatSearchIndex::INVALID) {
                // A failed CONCURRENTLY build leaves an invalid index that IF NOT EXISTS would keep: drop it first.
                $this->index->execute($this->index->dropSql());
            }
            $this->index->execute($sql);
        } catch (Throwable $exception) {
            if (!(new TransientFailureClassifier())->isTransient($exception)) {
                throw $exception;
            }
            $io->error(sprintf('%s: a lock wait, deadlock or lock timeout stopped the statement. No usable index was built; on PostgreSQL an INVALID index may remain until --force runs again (and after dropping an INVALID index first, none is left). Run the command again when the table is quieter.', ConflictCode::RETRY_SAFE));

            return self::CONFLICT;
        }
        $io->success($drop ? 'Index dropped.' : 'Index built.');

        return Command::SUCCESS;
    }
}

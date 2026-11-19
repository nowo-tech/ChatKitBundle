<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Command;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Search\ChatSearchText;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function count;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function sprintf;

/**
 * 1.37 (audit VA2-1): fills `nowo_chat_message.search_text` for the messages written before 1.37 (rows with a NULL
 * search text are never found by message search). Walks those rows by id in batches; each batch is one short
 * transaction on ChatKit's connection (DBAL only: no entity manager is flushed, cleared or reset) that reads its rows
 * with a row lock and writes a row only while its search text is still NULL, so a send or an edit always wins. Safe to run again
 * and while the chat is in use; `--after-id` resumes. A lock wait, deadlock or lock timeout stops it with
 * `CONFLICT_001` and exit code 75 (the batches before it are kept: REQ-RUNTIME-003 rule 6); other errors are rethrown.
 */
#[AsCommand(name: 'nowo:chat:search-backfill', description: 'Fill the search text of chat messages written before 1.37')]
final class ChatSearchBackfillCommand extends Command
{
    /** Largest `--batch` (one batch stays a short transaction). */
    public const int MAX_BATCH = 2000;

    /** Exit code of a lock conflict (EX_TEMPFAIL: try again later). */
    public const int CONFLICT = ChatSearchIndexCommand::CONFLICT;

    /**
     * @param EntityManagerInterface $entityManager ChatKit's manager (the private one when configured): its connection
     *                                              and the message mapping only
     */
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Messages per batch (at most 2000)', '500')
            ->addOption('after-id', null, InputOption::VALUE_REQUIRED, 'Start after this message id (resume)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $batch      = min(self::MAX_BATCH, max(1, (int) $input->getOption('batch')));
        $afterId    = max(0, (int) $input->getOption('after-id'));
        $connection = $this->entityManager->getConnection();
        $metadata   = $this->entityManager->getClassMetadata(ChatMessage::class);
        $table      = $metadata->getTableName();
        $id         = $metadata->getSingleIdentifierColumnName();
        $body       = $metadata->getColumnName('body');
        $search     = $metadata->getColumnName('searchText');
        $filled     = 0;
        // Audit VA3-1: the rows are read inside the batch's transaction with a row lock (FOR UPDATE on MySQL / MariaDB /
        // PostgreSQL), so an edit cannot commit between the read of a body and the write of its search text: it either
        // waits for the batch, or committed first (its search text is set and the row is no longer selected).
        $lock  = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform || $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
        $read  = sprintf('SELECT %1$s AS id, %2$s AS body FROM %3$s WHERE %4$s IS NULL AND %1$s > ? ORDER BY %1$s LIMIT %5$d%6$s', $id, $body, $table, $search, $batch, $lock);
        $write = sprintf('UPDATE %1$s SET %2$s = ? WHERE %3$s = ? AND %2$s IS NULL', $table, $search, $id);
        do {
            try {
                // @igor-ignore - The runner calls the closure once, now, and keeps nothing (stateless, per call).
                [$count, $written, $last] = ChatKitTransactionRunner::forConnection($connection, null)->run(static function () use ($connection, $read, $write, $afterId): array {
                    $rows    = $connection->fetchAllAssociative($read, [$afterId], [ParameterType::INTEGER]);
                    $written = 0;
                    $last    = $afterId;
                    foreach ($rows as $row) {
                        $written += (int) $connection->executeStatement(
                            $write,
                            [ChatSearchText::indexable(is_string($row['body']) ? $row['body'] : ''), $row['id']],
                            [ParameterType::STRING, ParameterType::INTEGER],
                        );
                        $last = is_numeric($row['id']) ? (int) $row['id'] : $last;
                    }

                    return [count($rows), $written, $last];
                });
            } catch (Throwable $exception) {
                if (!(new TransientFailureClassifier())->isTransient($exception)) {
                    throw $exception;
                }
                $io->error(sprintf('%s: a lock wait, deadlock or lock timeout stopped the batch after message %d; it was rolled back, the batches before it are kept (%d message(s) filled). Run again with --after-id=%d.', ConflictCode::RETRY_SAFE, $afterId, $filled, $afterId));

                return self::CONFLICT;
            }
            $filled += $written;
            $afterId = $last;
        } while ($count === $batch);

        $io->success(sprintf('Filled the search text of %d chat message(s).', $filled));

        return Command::SUCCESS;
    }
}

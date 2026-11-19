<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Command;

use Doctrine\DBAL\ArrayParameterType;
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

use function array_map;
use function count;
use function intval;
use function is_string;
use function max;
use function min;
use function sprintf;

/**
 * 1.37 (audit VA2-1): fills `nowo_chat_message.search_text` for the messages written before 1.37 (rows with a NULL
 * search text are never found by message search). Walks those rows by id in batches; each batch is one short
 * transaction on ChatKit's connection (DBAL only: no entity manager is flushed, cleared or reset) that locks only its
 * own rows, by id, and writes a row only while its search text is still NULL, so a send or an edit always wins. Safe to run again
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

    /**
     * The three statements of a batch (audit VA4-2): find the next candidate ids without a lock, read and lock those rows
     * by id inside the batch's transaction (`FOR UPDATE` on MySQL / MariaDB / PostgreSQL: record locks only, no range
     * lock that would hold back new messages), and write one search text while it is still NULL.
     *
     * @param EntityManagerInterface $entityManager ChatKit's manager (mapping and platform)
     * @param int $batch Rows per batch
     *
     * @return array{0: string, 1: string, 2: string} The find, read and write SQL
     */
    public static function statements(EntityManagerInterface $entityManager, int $batch): array
    {
        $metadata = $entityManager->getClassMetadata(ChatMessage::class);
        $platform = $entityManager->getConnection()->getDatabasePlatform();
        $table    = $metadata->getTableName();
        $id       = $metadata->getSingleIdentifierColumnName();
        $body     = $metadata->getColumnName('body');
        $search   = $metadata->getColumnName('searchText');
        $lock     = $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';

        return [
            sprintf('SELECT %1$s FROM %2$s WHERE %3$s IS NULL AND %1$s > ? ORDER BY %1$s LIMIT %4$d', $id, $table, $search, max(1, $batch)),
            sprintf('SELECT %1$s AS id, %2$s AS body FROM %3$s WHERE %1$s IN (?) AND %4$s IS NULL ORDER BY %1$s%5$s', $id, $body, $table, $search, $lock),
            sprintf('UPDATE %1$s SET %2$s = ? WHERE %3$s = ? AND %2$s IS NULL', $table, $search, $id),
        ];
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
        $filled     = 0;
        // Audits VA3-1 / VA4-2: the candidate ids are read first without a lock (a locking range scan would take next-key
        // locks on the primary key and hold back every send for the whole batch); then, inside the batch's transaction,
        // only those rows are locked by id (record locks, FOR UPDATE on MySQL / MariaDB / PostgreSQL) and read again with
        // their body, so an edit cannot commit between the read of a body and the write of its search text: it either
        // waits for this short batch, or committed first (its search text is set and the row is skipped).
        [$find, $read, $write] = self::statements($this->entityManager, $batch);
        do {
            $ids = array_map(intval(...), $connection->fetchFirstColumn($find, [$afterId], [ParameterType::INTEGER]));
            if ($ids === []) {
                break;
            }

            try {
                // @igor-ignore - The runner calls the closure once, now, and keeps nothing (stateless, per call).
                $written = (int) ChatKitTransactionRunner::forConnection($connection, null)->run(static function () use ($connection, $read, $write, $ids): int {
                    $written = 0;
                    foreach ($connection->fetchAllAssociative($read, [$ids], [ArrayParameterType::INTEGER]) as $row) {
                        $written += (int) $connection->executeStatement(
                            $write,
                            [ChatSearchText::indexable(is_string($row['body']) ? $row['body'] : ''), $row['id']],
                            [ParameterType::STRING, ParameterType::INTEGER],
                        );
                    }

                    return $written;
                });
            } catch (Throwable $exception) {
                if (!(new TransientFailureClassifier())->isTransient($exception)) {
                    throw $exception;
                }
                $io->error(sprintf('%s: a lock wait, deadlock or lock timeout stopped the batch after message %d; it was rolled back, the batches before it are kept (%d message(s) filled). Run again with --after-id=%d.', ConflictCode::RETRY_SAFE, $afterId, $filled, $afterId));

                return self::CONFLICT;
            }
            $filled += $written;
            $afterId = $ids[count($ids) - 1];
        } while (count($ids) === $batch);

        $io->success(sprintf('Filled the search text of %d chat message(s).', $filled));

        return Command::SUCCESS;
    }
}

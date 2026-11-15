<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Transaction;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use PDOException;

use function array_shift;

/**
 * 1.30: the TransactionRunnerInterface a test host binds, around ChatKit's default runner. Each run takes the next step
 * of `$script` (none, or `pass`: the default runner runs it):
 *
 * - `unavailable`: TransientStorageFailure (Retry-After 7) before anything runs;
 * - `replay`: what a retrying runner (DoctrineDeadlockRetryBundle's adapter) does after a deadlock at commit — the
 *   work runs, the storage fails transiently, the default runner rolls it back and discards the manager's state, and
 *   the work runs again.
 */
final class ScriptedTransactionRunner implements TransactionRunnerInterface
{
    /** @var list<'pass'|'replay'|'unavailable'> */
    public array $script = [];

    /** Runs asked for. */
    public int $runs = 0;

    /** Attempts made (a replay makes two). */
    public int $attempts = 0;

    public function __construct(private readonly TransactionRunnerInterface $inner)
    {
    }

    public function run(callable $operation): mixed
    {
        ++$this->runs;
        $step = array_shift($this->script);
        if ($step === 'unavailable') {
            throw new TransientStorageFailure(7);
        }
        if ($step === 'replay') {
            try {
                $this->inner->run(function () use ($operation): never {
                    ++$this->attempts;
                    $operation();

                    throw new DeadlockException(PdoDriverException::new(new PDOException('Deadlock found when trying to get lock')), null);
                });
            } catch (TransientStorageFailure) {
                // Retried below, as DoctrineDeadlockRetryBundle's adapter does.
            }
        }
        ++$this->attempts;

        return $this->inner->run($operation);
    }
}

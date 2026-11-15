<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Transaction;

use Nowo\TransactionContract\TransactionRunnerInterface;

/**
 * 1.30: counts the runs, then hands each to the given runner (or runs the work as it is).
 */
final class CountingTransactionRunner implements TransactionRunnerInterface
{
    public int $runs = 0;

    public function __construct(private readonly ?TransactionRunnerInterface $inner = null)
    {
    }

    public function run(callable $operation): mixed
    {
        ++$this->runs;

        return $this->inner instanceof TransactionRunnerInterface ? $this->inner->run($operation) : $operation();
    }
}

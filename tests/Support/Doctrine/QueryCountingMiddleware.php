<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

use function func_get_args;

/**
 * DBAL middleware recording every executed statement into the QueryCounter (query-count regression tests).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class QueryCountingMiddleware implements Middleware
{
    public function __construct(private QueryCounter $counter)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $counter = $this->counter;

        return new class($driver, $counter) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryCounter $counter)
            {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                $counter = $this->counter;

                return new class(parent::connect($params), $counter) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly QueryCounter $counter)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $counter = $this->counter;

                        return new class(parent::prepare($sql), $counter, $sql) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private readonly QueryCounter $counter, private readonly string $sql)
                            {
                                parent::__construct($statement);
                            }

                            /** The optional parameter is for DBAL 3, whose execute() takes one. */
                            public function execute(mixed $params = null): Result
                            {
                                $this->counter->record($this->sql);

                                return parent::execute(...func_get_args());
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $this->counter->record($sql);

                        return parent::query($sql);
                    }

                    /** `int` fits DBAL 3 (`int`) and DBAL 4 (`int|string`). */
                    public function exec(string $sql): int
                    {
                        $this->counter->record($sql);

                        return (int) parent::exec($sql);
                    }
                };
            }
        };
    }
}

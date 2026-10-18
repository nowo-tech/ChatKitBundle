<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Doctrine;

use function count;

/**
 * Counts the SQL statements the test connection runs (fed by QueryCountingMiddleware). Not resettable on
 * purpose: the services resetter between browser requests must not clear it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class QueryCounter
{
    /** @var list<string> */
    private array $queries = [];

    public function record(string $sql): void
    {
        $this->queries[] = $sql;
    }

    public function clear(): void
    {
        $this->queries = [];
    }

    public function count(): int
    {
        return count($this->queries);
    }

    /**
     * @return list<string>
     */
    public function queries(): array
    {
        return $this->queries;
    }
}

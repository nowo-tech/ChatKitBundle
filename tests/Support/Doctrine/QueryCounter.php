<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Doctrine;

use Closure;

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

    /**
     * 1.30: runs before the next statements; it returns true once it acted (then it is removed). The statements it runs
     * itself do not call it again (race tests: another request writes a row just before this one's insert).
     *
     * @var (Closure(string): bool)|null
     */
    public ?Closure $before = null;

    public function record(string $sql): void
    {
        $this->queries[] = $sql;
        $hook            = $this->before;
        if ($hook instanceof Closure) {
            $this->before = null;
            if (!$hook($sql)) {
                $this->before = $hook;
            }
        }
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

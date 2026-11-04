<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Chat;

use Psr\Log\AbstractLogger;
use Stringable;

use function array_map;
use function implode;
use function is_scalar;

/**
 * Logger double: one line per record, `<level> <message> <context values>`.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = $level . ' ' . $message . ' ' . implode(' ', array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '?', $context));
    }
}

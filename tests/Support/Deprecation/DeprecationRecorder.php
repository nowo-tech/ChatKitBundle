<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Deprecation;

use function restore_error_handler;
use function set_error_handler;
use function str_contains;

use const E_USER_DEPRECATED;

/**
 * 1.39: records the `E_USER_DEPRECATED` messages that contain a marker (silenced ones included, as trigger_deprecation()
 * and the host-flush probe trigger them) while it runs; every other error goes to the previous handler.
 */
final class DeprecationRecorder
{
    /**
     * @template T
     *
     * @param string $marker Only messages containing it are recorded
     * @param callable(): T $work The code to watch
     *
     * @return array{0: T, 1: list<string>} What the work returned, and the recorded messages
     */
    public static function record(string $marker, callable $work): array
    {
        $messages = [];
        // Test-only, restored in the finally below.
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$messages, $marker, &$previous): bool {
            if ($level === E_USER_DEPRECATED && str_contains($message, $marker)) {
                $messages[] = $message;

                return true;
            }

            return $previous !== null ? (bool) $previous($level, $message, $file, $line) : false;
        });
        try {
            $result = $work();
        } finally {
            restore_error_handler();
        }

        return [$result, $messages];
    }
}

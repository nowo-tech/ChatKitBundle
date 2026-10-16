<?php

declare(strict_types=1);

/**
 * Adds missing @return tags to public methods that already have PHPDoc.
 * With --check it only reports them and exits 1 (release-check never rewrites sources).
 */
$srcDir = dirname(__DIR__) . '/src';
$check = in_array('--check', $argv, true);
$pending = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    $updated = preg_replace_callback(
        '#(/\*\*(?:(?!\*/).)*?\*/)\s*public\s+(?:static\s+)?function\s+\w+\s*\([^)]*\)\s*:\s*([^\s{;]+)#s',
        static function (array $matches): string {
            $doc = $matches[1];
            $returnType = $matches[2];

            if (str_contains($doc, '@return')) {
                return $matches[0];
            }

            $returnTag = match ($returnType) {
                'void' => '     * @return void',
                'self' => '     * @return self Fluent interface',
                'bool' => '     * @return bool',
                'string' => '     * @return string',
                'int' => '     * @return int',
                'float' => '     * @return float',
                'array' => '     * @return array',
                default => str_starts_with($returnType, '?') || str_contains($returnType, '\\')
                    ? '     * @return ' . $returnType
                    : '     * @return ' . $returnType,
            };

            $newDoc = preg_replace('/\n\s*\*\/\s*$/', "\n     *\n" . $returnTag . "\n     */", $doc, 1);
            if (!is_string($newDoc)) {
                return $matches[0];
            }

            return str_replace($doc, $newDoc, $matches[0]);
        },
        $content,
    );

    if (is_string($updated) && $updated !== $content) {
        ++$pending;
        if ($check) {
            echo 'Missing @return: ' . str_replace($srcDir . '/', '', $path) . PHP_EOL;
            continue;
        }
        file_put_contents($path, $updated);
        echo 'Updated: ' . str_replace($srcDir . '/', '', $path) . PHP_EOL;
    }
}

exit($check && $pending > 0 ? 1 : 0);

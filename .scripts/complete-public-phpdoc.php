<?php

declare(strict_types=1);

/**
 * Checks that every class-like and every public method in src/ has a PHPDoc block (REQ-CS-001).
 *
 * Read-only: it never rewrites sources. @param/@return tags that only repeat a native type are left to
 * php-cs-fixer (no_superfluous_phpdoc_tags), so a description line is enough. Exits 1 when a docblock is missing.
 */
$srcDir = dirname(__DIR__) . '/src';
$missing = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    $tokens = token_get_all($content);
    $count = count($tokens);
    $relative = str_replace($srcDir . '/', '', $path);

    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }

        $kind = $token[0];
        if (!in_array($kind, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION], true)) {
            continue;
        }

        // Anonymous classes, closures, `::class` constants and `use function` imports are not declarations.
        $next = $i + 1;
        while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            ++$next;
        }
        if ($next >= $count || !is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING) {
            continue;
        }
        $name = $tokens[$next][1];
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
            --$prev;
        }
        if ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_NEW, T_USE], true)) {
            continue;
        }

        // Walk back over modifiers and attributes to the docblock, if any.
        $public = $kind !== T_FUNCTION;
        $explicitVisibility = false;
        $doc = false;
        for ($j = $i - 1; $j >= 0; --$j) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_DOC_COMMENT) {
                $doc = true;
                break;
            }
            if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                $explicitVisibility = true;
                $public = $public || $t[0] === T_PUBLIC;
                if ($kind === T_FUNCTION && $t[0] !== T_PUBLIC) {
                    $public = false;
                }
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                continue;
            }
            if ($t === ']') {
                // Skip an attribute group #[...].
                $depth = 0;
                for (; $j >= 0; --$j) {
                    if ($tokens[$j] === ']') {
                        ++$depth;
                    } elseif ($tokens[$j] === '[' || (is_array($tokens[$j]) && $tokens[$j][0] === T_ATTRIBUTE)) {
                        --$depth;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                continue;
            }
            break;
        }

        // Interface methods and methods without a visibility keyword are public.
        if ($kind === T_FUNCTION && !$explicitVisibility) {
            $public = true;
        }

        if ($public && !$doc) {
            $missing[] = sprintf('%s:%d %s %s', $relative, $token[2], $kind === T_FUNCTION ? 'method' : 'class', $name);
        }
    }
}

foreach ($missing as $line) {
    echo 'Missing PHPDoc: ' . $line . PHP_EOL;
}

exit($missing === [] ? 0 : 1);

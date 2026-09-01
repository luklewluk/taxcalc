<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\CsvSource;

/**
 * Reads CSV files given as CLI arguments.
 *
 * Unlike the web upload path this reads from a user-chosen path on purpose -
 * that is what a CLI argument means - but it still refuses anything that is not
 * a readable regular file, so a typo produces a message rather than a stack
 * trace.
 */
final class CsvFileLoader
{
    /**
     * @param string|list<string>|mixed $paths one path or a list of paths
     *
     * @return array{list<CsvSource>, list<string>} sources and error messages
     */
    public function load(mixed $paths): array
    {
        $sources = [];
        $errors = [];

        foreach (self::normalize($paths) as $path) {
            if (!is_file($path) || !is_readable($path)) {
                $errors[] = sprintf('Nie można odczytać pliku "%s".', $path);

                continue;
            }

            $content = @file_get_contents($path);
            if (false === $content) {
                $errors[] = sprintf('Nie można odczytać pliku "%s".', $path);

                continue;
            }

            if ('' === trim($content)) {
                $errors[] = sprintf('Plik "%s" jest pusty.', $path);

                continue;
            }

            $sources[] = new CsvSource(basename($path), $content);
        }

        return [$sources, $errors];
    }

    /**
     * A single-argument invocation still arrives as a plain string from some
     * callers, so accept both shapes rather than blowing up on a TypeError.
     *
     * @return list<string>
     */
    private static function normalize(mixed $paths): array
    {
        if (is_string($paths)) {
            return '' === $paths ? [] : [$paths];
        }

        if (!is_array($paths)) {
            return [];
        }

        $result = [];
        foreach ($paths as $path) {
            if (is_string($path) && '' !== $path) {
                $result[] = $path;
            }
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Import;

/**
 * An uploaded CSV held entirely in memory.
 *
 * The application never writes uploaded content to disk; PHP's own temporary
 * upload file is read once and deleted immediately (see
 * {@see \App\Web\Upload\UploadedCsvReader}).
 */
final readonly class CsvSource
{
    public string $name;

    public string $content;

    public function __construct(string $name, string $content)
    {
        // Only the basename is ever kept, and only for display in messages -
        // never used to build a filesystem path.
        $this->name = self::sanitizeName($name);
        $this->content = self::stripBom($content);
    }

    /**
     * @return list<string> non-empty lines, for format sniffing
     */
    public function firstLines(int $limit = 5): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $this->content) ?: [] as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $lines[] = $line;
            if (count($lines) >= $limit) {
                break;
            }
        }

        return $lines;
    }

    private static function sanitizeName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^\w.\- ]/u', '_', $base) ?? 'plik.csv';

        return '' === trim($base) ? 'plik.csv' : mb_substr(trim($base), 0, 120);
    }

    private static function stripBom(string $content): string
    {
        return str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;
    }
}

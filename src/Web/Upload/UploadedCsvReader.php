<?php

declare(strict_types=1);

namespace App\Web\Upload;

use App\Import\CsvSource;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Reads uploaded CSVs into memory and validates them before anything else in
 * the application sees them.
 *
 * The uploaded bytes are read once from PHP's own temporary upload file and
 * that file is deleted straight away. Nothing is copied into the project, into
 * `var/`, or into the session - the content only lives for the duration of the
 * request.
 *
 * Validation is deliberately layered: extension, declared MIME type, size, and
 * finally the bytes themselves, because the first three are all attacker
 * controlled.
 */
final readonly class UploadedCsvReader
{
    /**
     * @var list<string>
     */
    private const array ALLOWED_EXTENSIONS = ['csv', 'txt'];

    /**
     * @var list<string>
     */
    private const array ALLOWED_MIME_TYPES = [
        'text/csv',
        'text/plain',
        'application/csv',
        'application/vnd.ms-excel',
        'application/octet-stream',
        'text/x-csv',
        'text/comma-separated-values',
    ];

    public function __construct(
        private int $maxFiles,
        private int $maxBytes,
    ) {
    }

    /**
     * @param list<UploadedFile> $files
     */
    public function read(array $files): UploadReadResult
    {
        $sources = [];
        $rejections = [];

        foreach ($files as $index => $file) {
            $name = self::displayName($file);

            if ($index >= $this->maxFiles) {
                $rejections[] = new UploadRejection($name, sprintf(
                    'przekroczono limit %d plików na jedno przesłanie.',
                    $this->maxFiles,
                ));
                self::discard($file);

                continue;
            }

            $rejection = $this->validate($file, $name);
            if (null !== $rejection) {
                $rejections[] = $rejection;
                self::discard($file);

                continue;
            }

            $content = @file_get_contents($file->getPathname());
            self::discard($file);

            if (false === $content) {
                $rejections[] = new UploadRejection($name, 'nie udało się odczytać zawartości pliku.');

                continue;
            }

            $contentRejection = $this->validateContent($content, $name);
            if (null !== $contentRejection) {
                $rejections[] = $contentRejection;

                continue;
            }

            $sources[] = new CsvSource($name, $content);
        }

        return new UploadReadResult($sources, $rejections);
    }

    private function validate(UploadedFile $file, string $name): ?UploadRejection
    {
        if (!$file->isValid()) {
            return new UploadRejection($name, 'przesyłanie nie powiodło się - spróbuj ponownie.');
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return new UploadRejection($name, sprintf(
                'niedozwolone rozszerzenie - akceptujemy wyłącznie: %s.',
                implode(', ', self::ALLOWED_EXTENSIONS),
            ));
        }

        $mimeType = $file->getClientMimeType();
        if (!in_array(strtolower($mimeType), self::ALLOWED_MIME_TYPES, true)) {
            return new UploadRejection($name, 'nieobsługiwany typ pliku - oczekiwano pliku tekstowego CSV.');
        }

        $size = $file->getSize();
        if (false === $size || 0 === $size) {
            return new UploadRejection($name, 'plik jest pusty.');
        }

        if ($size > $this->maxBytes) {
            return new UploadRejection($name, sprintf(
                'plik jest za duży (limit %s).',
                self::formatBytes($this->maxBytes),
            ));
        }

        return null;
    }

    private function validateContent(string $content, string $name): ?UploadRejection
    {
        if ('' === trim($content)) {
            return new UploadRejection($name, 'plik jest pusty.');
        }

        // A CSV never contains NUL bytes; anything that does is binary.
        if (str_contains($content, "\0")) {
            return new UploadRejection($name, 'plik nie jest tekstowym plikiem CSV.');
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return new UploadRejection(
                $name,
                'plik nie jest zapisany w UTF-8 - zapisz go ponownie w kodowaniu UTF-8.',
            );
        }

        // Reject other control characters (tab, CR and LF are legitimate).
        if (1 === preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $content)) {
            return new UploadRejection($name, 'plik zawiera znaki sterujące - to nie jest plik CSV.');
        }

        $firstLine = strtok($content, "\r\n");
        if (false === $firstLine || !preg_match('/[,;\t]/', $firstLine)) {
            return new UploadRejection(
                $name,
                'pierwszy wiersz nie wygląda jak nagłówek CSV (brak separatora , ; lub tabulatora).',
            );
        }

        return null;
    }

    private static function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[^\w.\- ]/u', '_', $name) ?? '';
        $name = trim($name);

        return '' === $name ? 'plik.csv' : mb_substr($name, 0, 120);
    }

    /**
     * Removes PHP's temporary upload file as soon as we are done with it.
     */
    private static function discard(UploadedFile $file): void
    {
        $path = $file->getPathname();
        if ('' !== $path && is_file($path)) {
            @unlink($path);
        }
    }

    private static function formatBytes(int $bytes): string
    {
        return sprintf('%.1f MB', $bytes / 1024 / 1024);
    }
}

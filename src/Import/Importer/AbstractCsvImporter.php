<?php

declare(strict_types=1);

namespace App\Import\Importer;

use App\Import\CsvDelimiter;
use App\Import\CsvSource;
use App\Import\ImportMessage;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use League\Csv\UnavailableStream;

/**
 * Shared plumbing for the header-based (flat) CSV importers.
 */
abstract class AbstractCsvImporter implements ImporterInterface
{
    /**
     * Hard ceiling on data rows read from a single file.
     *
     * Applied while iterating so that an oversized upload is bounded before it
     * becomes an array of domain objects, rather than after.
     */
    public const int DEFAULT_MAX_ROWS_PER_FILE = 50000;

    public function __construct(
        protected readonly int $maxRowsPerFile = self::DEFAULT_MAX_ROWS_PER_FILE,
    ) {
    }

    /**
     * Reads the file into a header-keyed row list.
     *
     * The second slot holds *fatal* file-level messages: when it is non-empty
     * the caller must give up on the file. The third holds non-fatal notices.
     * A row-limit breach is fatal: returning a prefix could produce a plausible
     * but understated tax result.
     *
     * @param list<string> $requiredColumns
     *
     * @return array{list<array<string, string>>, list<ImportMessage>, list<ImportMessage>}
     */
    final protected function readRows(CsvSource $source, array $requiredColumns): array
    {
        $lines = $source->firstLines(1);
        if ([] === $lines) {
            return [[], [ImportMessage::error($source->name, 'Plik jest pusty.')], []];
        }

        try {
            $reader = Reader::fromString($source->content);
            $reader->setDelimiter(CsvDelimiter::detect($lines[0]));
            $reader->setHeaderOffset(0);
            $header = array_map(
                static fn (mixed $column): string => mb_strtolower(trim((string) $column)),
                $reader->getHeader(),
            );
        } catch (CsvException|UnavailableStream $e) {
            return [[], [ImportMessage::error($source->name, 'Nie udało się odczytać pliku CSV: '.$e->getMessage())], []];
        }

        $missing = array_diff($requiredColumns, $header);
        if ([] !== $missing) {
            return [[], [ImportMessage::error(
                $source->name,
                sprintf('Brakuje wymaganych kolumn: %s.', implode(', ', $missing)),
            )], []];
        }

        $rows = [];
        $truncated = false;
        try {
            foreach ($reader->getRecords($header) as $record) {
                if (count($rows) >= $this->maxRowsPerFile) {
                    // Stop consuming the iterator: the rest of the file is never
                    // parsed into memory at all.
                    $truncated = true;
                    break;
                }

                $normalized = [];
                foreach ($record as $key => $value) {
                    $normalized[(string) $key] = is_scalar($value) ? trim((string) $value) : '';
                }

                $rows[] = $normalized;
            }
        } catch (CsvException $e) {
            return [[], [ImportMessage::error($source->name, 'Nie udało się odczytać pliku CSV: '.$e->getMessage())], []];
        }

        if ($truncated) {
            return [[], [self::truncationError($source, $this->maxRowsPerFile)], []];
        }

        return [$rows, [], []];
    }

    final protected static function truncationError(CsvSource $source, int $limit): ImportMessage
    {
        return ImportMessage::error($source->name, sprintf(
            'Plik zawiera więcej niż %d wierszy. Nie wczytano żadnych danych; podziel go na mniejsze części.',
            $limit,
        ));
    }

    /**
     * CSV line number of a zero-based data row, counting the header as line 1.
     */
    final protected static function lineNumber(int $rowIndex): int
    {
        return $rowIndex + 2;
    }
}

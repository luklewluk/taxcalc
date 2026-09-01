<?php

declare(strict_types=1);

namespace App\Import\Degiro;

use App\Import\CsvDelimiter;
use App\Import\CsvSource;
use App\Import\ImportMessage;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use League\Csv\UnavailableStream;

/**
 * Reads a DEGIRO CSV into positional rows.
 *
 * Separate from {@see \App\Import\Importer\AbstractCsvImporter} on purpose: that
 * reader keys rows by header name, which cannot represent the duplicated blank
 * headers DEGIRO uses to carry the currency of each amount.
 *
 * Row width is enforced strictly. A row that is a field short or a field long
 * shifts every following column, so a "USD" would be read as an amount and an
 * amount as a currency; guessing there could quietly change a tax figure, so
 * such a row is reported and left out.
 */
final class DegiroCsvReader
{
    /**
     * @return array{DegiroTable|null, list<ImportMessage>} the table (null when
     *                                                      the file is unusable) and the messages collected
     */
    public static function read(CsvSource $source, int $maxRows): array
    {
        $lines = $source->firstLines(1);
        if ([] === $lines) {
            return [null, [ImportMessage::error($source->name, 'Plik jest pusty.')]];
        }

        $delimiter = CsvDelimiter::detect($lines[0]);

        try {
            $reader = Reader::fromString($source->content);
            $reader->setDelimiter($delimiter);
            $records = $reader->getRecords();

            $header = null;
            $rows = [];
            $messages = [];

            foreach ($records as $offset => $record) {
                $fields = self::fields($record);
                $line = (int) $offset + 1;

                if (self::isBlank($fields)) {
                    continue;
                }

                if (null === $header) {
                    $header = DegiroHeader::fromFields($fields);

                    continue;
                }

                if (count($rows) >= $maxRows) {
                    // Fail closed rather than settling a prefix of the file.
                    return [null, [ImportMessage::error($source->name, sprintf(
                        'Plik zawiera więcej niż %d wierszy. Nie wczytano żadnych danych; podziel go na mniejsze części.',
                        $maxRows,
                    ))]];
                }

                if (count($fields) !== $header->count()) {
                    $messages[] = ImportMessage::error($source->name, sprintf(
                        'Wiersz ma %d kolumn, a nagłówek %d - pomijam go, bo przesunięte kolumny '
                        .'oznaczałyby odczytanie kwoty lub waluty z innego pola.',
                        count($fields),
                        $header->count(),
                    ), $line);

                    continue;
                }

                $rows[] = [$line, $fields];
            }
        } catch (CsvException|UnavailableStream $e) {
            return [null, [ImportMessage::error($source->name, 'Nie udało się odczytać pliku CSV: '.$e->getMessage())]];
        }

        if (null === $header) {
            return [null, [ImportMessage::error($source->name, 'Plik nie zawiera nagłówka.')]];
        }

        return [new DegiroTable($header, $rows, $delimiter), $messages];
    }

    /**
     * @param array<array-key, mixed> $record
     *
     * @return list<string>
     */
    private static function fields(array $record): array
    {
        $fields = [];
        foreach ($record as $value) {
            $fields[] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $fields;
    }

    /**
     * @param list<string> $fields
     */
    private static function isBlank(array $fields): bool
    {
        foreach ($fields as $field) {
            if ('' !== $field) {
                return false;
            }
        }

        return true;
    }
}

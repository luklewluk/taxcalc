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
 * Row width is enforced strictly except for missing or surplus empty fields at
 * the very end that correspond to unnamed trailing headers. Any real shift is
 * still refused: otherwise a "USD" could be read as an amount and an amount as
 * a currency, quietly changing a tax figure.
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

                $fields = self::normalizeWidth($fields, $header);
                if (null === $fields) {
                    $messages[] = ImportMessage::error($source->name, sprintf(
                        'Wiersz ma %d kolumn, a nagłówek %d - pomijam go, bo przesunięte kolumny '
                        .'oznaczałyby odczytanie kwoty lub waluty z innego pola.',
                        count(self::fields($record)),
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

        [$decimalComma, $numberFormatConflict] = self::decimalComma($rows, $header);
        if ($numberFormatConflict) {
            return [null, [...$messages, ImportMessage::error(
                $source->name,
                'Plik zawiera sprzeczne konwencje zapisu liczb: część wartości używa przecinka, '
                .'a część kropki jako separatora dziesiętnego. Import przerwano, aby nie zgadywać kwot.',
            )]];
        }

        return [new DegiroTable($header, $rows, $delimiter, $decimalComma), $messages];
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

    /**
     * A trailing delimiter is not economically meaningful, but an empty field
     * in the middle is. Width is therefore repaired only where the header's
     * own trailing columns are unnamed; every other mismatch remains fatal.
     *
     * @param list<string> $fields
     *
     * @return list<string>|null
     */
    private static function normalizeWidth(array $fields, DegiroHeader $header): ?array
    {
        $expected = $header->count();
        $actual = count($fields);

        if ($actual === $expected) {
            return $fields;
        }

        if ($actual < $expected) {
            for ($index = $actual; $index < $expected; ++$index) {
                if ('' !== $header->columns[$index]) {
                    return null;
                }
            }

            return array_pad($fields, $expected, '');
        }

        if ('' !== $header->columns[$expected - 1]) {
            return null;
        }

        foreach (array_slice($fields, $expected) as $extra) {
            if ('' !== $extra) {
                return null;
            }
        }

        return array_slice($fields, 0, $expected);
    }

    /**
     * Learns the file-wide decimal convention only from values that identify it
     * unambiguously. A single `1,234` contributes no clue; `12,50`, `1,234.50`
     * and `1.234.567` do. Text, dates and times are ignored.
     *
     * @param list<array{int, list<string>}> $rows
     *
     * @return array{bool|null, bool} convention (true = decimal comma) and conflict flag
     */
    private static function decimalComma(array $rows, DegiroHeader $header): array
    {
        $found = null;

        $ignored = [];
        foreach ([
            DegiroHeader::DATE,
            DegiroHeader::TIME,
            DegiroHeader::PRODUCT,
            DegiroHeader::ISIN,
            DegiroHeader::DESCRIPTION,
            DegiroHeader::ORDER_ID,
        ] as $aliases) {
            foreach ($header->indexesOf($aliases) as $index) {
                $ignored[$index] = true;
                if (DegiroHeader::ORDER_ID === $aliases
                    && $index + 1 === $header->count() - 1
                    && '' === $header->columns[$index + 1]) {
                    $ignored[$index + 1] = true;
                }
            }
        }

        foreach ($rows as [, $fields]) {
            foreach ($fields as $index => $field) {
                if (isset($ignored[$index])) {
                    continue;
                }

                $convention = self::numberConvention($field);
                if (null === $convention) {
                    continue;
                }

                if (null !== $found && $found !== $convention) {
                    return [null, true];
                }

                $found = $convention;
            }
        }

        return [$found, false];
    }

    private static function numberConvention(string $value): ?bool
    {
        $number = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', trim($value)) ?? '';
        if (str_starts_with($number, '(') && str_ends_with($number, ')')) {
            $number = substr($number, 1, -1);
        }

        if (1 !== preg_match('/^[+-]?\d[\d.,]*$/', $number)) {
            return null;
        }

        $commas = substr_count($number, ',');
        $dots = substr_count($number, '.');

        if ($commas > 0 && $dots > 0) {
            return strrpos($number, ',') > strrpos($number, '.');
        }

        if (0 === $commas && 0 === $dots) {
            return null;
        }

        $separator = $commas > 0 ? ',' : '.';
        $count = max($commas, $dots);
        if ($count > 1) {
            $group = preg_quote($separator, '/');

            return 1 === preg_match('/^[+-]?\d{1,3}('.$group.'\d{3})+$/', $number)
                ? ',' !== $separator
                : null;
        }

        $at = strrpos($number, $separator);
        if (false === $at || 3 === strlen($number) - $at - 1) {
            return null;
        }

        return ',' === $separator;
    }
}

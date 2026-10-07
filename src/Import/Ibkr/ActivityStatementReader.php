<?php

declare(strict_types=1);

namespace App\Import\Ibkr;

use App\Import\CsvSource;
use League\Csv\Exception as CsvException;
use League\Csv\Reader;
use League\Csv\UnavailableStream;

/**
 * Reads the sectioned Interactive Brokers Activity Statement CSV.
 *
 * Every record starts with the section name and a row kind:
 *
 *   Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,...
 *   Trades,Data,Order,Stocks,USD,AAA,"2026-06-22, 09:55:39",...
 *   Trades,SubTotal,,Stocks,USD,AAA,...
 *
 * A section may declare its header more than once - the Forex block of Trades
 * names its commission column `Comm in USD`, and Financial Instrument
 * Information has a separate header for options - so each `Data` row is keyed
 * by the header in force when it was read. `SubTotal`, `Total` and `Notes` rows
 * are not data.
 *
 * The whole file is read before anything is mapped: Financial Instrument
 * Information, which carries the ISIN and the listing exchange, comes *after*
 * the Trades section. Quoted fields contain commas (`"2026-06-22, 09:55:39"`),
 * so the file goes through a real CSV parser rather than a line split.
 *
 * Only the requested sections are kept. Account Information - the holder's
 * name and account number - is never asked for and so never held.
 */
final class ActivityStatementReader
{
    /**
     * @param list<string> $sections section names to keep, exactly as IBKR prints them
     *
     * @throws ActivityStatementReadException when the file cannot be used at all
     */
    public static function read(CsvSource $source, array $sections, int $maxRows): ActivityStatement
    {
        if ([] === $source->firstLines(1)) {
            throw new ActivityStatementReadException('Plik jest pusty.');
        }

        $wanted = array_fill_keys($sections, true);
        /** @var array<string, list<string>> $headers */
        $headers = [];
        /** @var array<string, list<ActivityStatementRow>> $rows */
        $rows = [];
        $count = 0;

        try {
            $reader = Reader::fromString($source->content);
            $reader->setDelimiter(',');

            foreach ($reader->getRecords() as $offset => $record) {
                $fields = array_map(
                    static fn (mixed $field): string => is_scalar($field) ? trim((string) $field) : '',
                    array_values($record),
                );

                if (count($fields) < 2 || !isset($wanted[$fields[0]])) {
                    continue;
                }

                [$section, $kind] = $fields;

                if ('Header' === $kind) {
                    $headers[$section] = array_map(mb_strtolower(...), array_slice($fields, 2));

                    continue;
                }

                if ('Data' !== $kind || !isset($headers[$section])) {
                    continue;
                }

                if (++$count > $maxRows) {
                    // Fail closed: a prefix of the statement could settle a
                    // convincing but incomplete tax year.
                    throw new ActivityStatementReadException(sprintf(
                        'Plik zawiera więcej niż %d wierszy. Nie wczytano żadnych danych; podziel go na mniejsze części.',
                        $maxRows,
                    ));
                }

                $rows[$section][] = new ActivityStatementRow(
                    $offset + 1,
                    self::combine($headers[$section], array_slice($fields, 2)),
                );
            }
        } catch (CsvException|UnavailableStream $e) {
            throw new ActivityStatementReadException('Nie udało się odczytać pliku CSV: '.$e->getMessage(), 0, $e);
        }

        return new ActivityStatement($rows);
    }

    /**
     * Unnamed columns (the Forex header leaves several blank) are dropped.
     *
     * @param list<string> $header
     * @param list<string> $values
     *
     * @return array<string, string>
     */
    private static function combine(array $header, array $values): array
    {
        $row = [];
        foreach ($header as $index => $column) {
            if ('' !== $column) {
                $row[$column] = $values[$index] ?? '';
            }
        }

        return $row;
    }
}

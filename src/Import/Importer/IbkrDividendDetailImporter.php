<?php

declare(strict_types=1);

namespace App\Import\Importer;

use App\Exception\InvalidCurrencyException;
use App\Exception\InvalidDateException;
use App\Exception\InvalidNumberException;
use App\Exception\InvalidRecordException;
use App\Import\CsvFormat;
use App\Import\CsvSource;
use App\Import\ImportMessage;
use App\Import\ImportResult;
use App\Import\Parser\DateParser;
use App\Import\Parser\NumberParser;
use App\Model\CountryCode;
use App\Model\Dividend;
use App\Money\Amount;

/**
 * Reads the sectioned Interactive Brokers tax statement.
 *
 * The file interleaves several sections, each row prefixed with its section
 * name and either `Header` or `Data`:
 *
 *   Account,Header,AccountNumber,...
 *   Account,Data,UXXXXXXXX,...
 *   DividendDetail,Header,DataDiscriminator,Currency,Symbol,...,Country,...
 *   DividendDetail,Data,Summary,USD,AAA,...,US,...
 *
 * Only the `DividendDetail` section is read, and within it only the `Summary`
 * rows - the `RevenueComponent` rows break the same payment down further and
 * would double-count. Unlike the flat exports this one carries the country of
 * origin, which is exactly what PIT/ZG needs.
 *
 * Nothing from the `Account` section (holder name, account number) is ever
 * read, so no personal identifier enters the application.
 */
final class IbkrDividendDetailImporter implements ImporterInterface
{
    private const string SECTION = 'DividendDetail';

    private const string SUMMARY_DISCRIMINATOR = 'Summary';

    /**
     * @var list<string>
     */
    private const array REQUIRED_COLUMNS = ['datadiscriminator', 'currency', 'symbol', 'country', 'reportdate', 'gross', 'withhold'];

    public function __construct(
        private readonly int $maxRowsPerFile = AbstractCsvImporter::DEFAULT_MAX_ROWS_PER_FILE,
    ) {
    }

    public function supports(CsvFormat $format): bool
    {
        return CsvFormat::IbkrDividendDetail === $format;
    }

    public function import(CsvSource $source): ImportResult
    {
        /** @var list<string>|null $header */
        $header = null;
        $dividends = [];
        $messages = [];
        $lineNumber = 0;

        foreach (preg_split('/\R/', $source->content) ?: [] as $line) {
            ++$lineNumber;

            if ('' === trim($line)) {
                continue;
            }

            $fields = str_getcsv($line, ',', '"', '\\');
            $fields = array_map(static fn (mixed $f): string => is_scalar($f) ? trim((string) $f) : '', $fields);

            if ((count($fields) < 2) || self::SECTION !== $fields[0]) {
                continue;
            }

            if ('Header' === $fields[1]) {
                $header = array_map(mb_strtolower(...), array_slice($fields, 2));

                $missing = array_diff(self::REQUIRED_COLUMNS, $header);
                if ([] !== $missing) {
                    return new ImportResult([], [], [ImportMessage::error(
                        $source->name,
                        sprintf('Sekcja DividendDetail nie zawiera kolumn: %s.', implode(', ', $missing)),
                        $lineNumber,
                    )]);
                }

                continue;
            }

            if ('Data' !== $fields[1] || null === $header) {
                continue;
            }

            $row = self::combine($header, array_slice($fields, 2));

            if (self::SUMMARY_DISCRIMINATOR !== ($row['datadiscriminator'] ?? '')) {
                continue;
            }

            if (count($dividends) >= $this->maxRowsPerFile) {
                // Fail closed: a prefix could yield a convincing but understated
                // tax result if the user continued from the review screen.
                return new ImportResult([], [], [...$messages, ImportMessage::error($source->name, sprintf(
                    'Plik zawiera więcej niż %d dywidend. Nie wczytano żadnych danych; podziel plik na mniejsze części.',
                    $this->maxRowsPerFile,
                ))]);
            }

            try {
                $currency = strtoupper($row['currency'] ?? '');
                $symbol = trim($row['symbol'] ?? '');
                if ('' === $symbol) {
                    $messages[] = ImportMessage::error($source->name, 'Brak symbolu instrumentu.', $lineNumber);

                    continue;
                }

                $withholding = NumberParser::parseOrZero($row['withhold'] ?? '');
                if ($withholding->isPositive()) {
                    throw new InvalidRecordException(sprintf(
                        'Pole Withhold ma dodatnią wartość %s %s (zwrot, nie pobrany podatek). Zweryfikuj rekord.',
                        (string) $withholding,
                        $currency,
                    ));
                }

                $dividends[] = new Dividend(
                    $symbol,
                    CountryCode::normalizeOptional($row['country'] ?? ''),
                    $currency,
                    DateParser::parse($row['reportdate'] ?? ''),
                    Amount::fromDecimal(NumberParser::parse($row['gross'] ?? ''), $currency),
                    // Withholding is reported as a negative number.
                    Amount::fromDecimal($withholding->abs(), $currency),
                    sprintf('%s (%s)', $source->name, CsvFormat::IbkrDividendDetail->label()),
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, $e->getMessage(), $lineNumber);
            }
        }

        if (null === $header) {
            return new ImportResult([], [], [ImportMessage::error(
                $source->name,
                'Plik nie zawiera sekcji "DividendDetail". Pobierz zestawienie dywidend z sekcji dokumentów podatkowych IBKR.',
            )]);
        }

        return new ImportResult([], $dividends, $messages);
    }

    /**
     * @param list<string> $header
     * @param list<string> $values
     *
     * @return array<string, string>
     */
    private static function combine(array $header, array $values): array
    {
        $row = [];
        foreach ($header as $index => $column) {
            $row[$column] = $values[$index] ?? '';
        }

        return $row;
    }
}

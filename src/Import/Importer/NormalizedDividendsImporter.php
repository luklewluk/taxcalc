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
use App\Tax\TaxRates;

/**
 * Reads the tool's own dividend format, unchanged since the CLI-only version:
 *
 *   name,country,currency,date,amount,tax_paid
 */
final class NormalizedDividendsImporter extends AbstractCsvImporter
{
    private const array REQUIRED = ['name', 'country', 'currency', 'date', 'amount', 'tax_paid'];

    public function __construct(
        private readonly TaxRates $taxRates = new TaxRates(),
        int $maxRowsPerFile = self::DEFAULT_MAX_ROWS_PER_FILE,
    ) {
        parent::__construct($maxRowsPerFile);
    }

    public function supports(CsvFormat $format): bool
    {
        return CsvFormat::NormalizedDividends === $format;
    }

    public function import(CsvSource $source): ImportResult
    {
        [$rows, $fatal, $notices] = $this->readRows($source, self::REQUIRED);
        if ([] !== $fatal) {
            return new ImportResult([], [], $fatal);
        }

        $dividends = [];
        $messages = $notices;
        $unknownCountries = [];

        foreach ($rows as $index => $row) {
            $line = self::lineNumber($index);

            try {
                $currency = strtoupper($row['currency'] ?? '');
                $name = trim($row['name'] ?? '');
                if ('' === $name) {
                    $messages[] = ImportMessage::error($source->name, 'Kolumna "name" jest pusta.', $line);

                    continue;
                }

                $country = CountryCode::normalizeOptional($row['country'] ?? '');
                if ('' !== $country && !$this->taxRates->isKnownCountry($country)) {
                    $unknownCountries[$country] = true;
                }

                $dividends[] = new Dividend(
                    $name,
                    $country,
                    $currency,
                    DateParser::parse($row['date'] ?? ''),
                    Amount::fromDecimal(NumberParser::parse($row['amount'] ?? '', decimalComma: true), $currency),
                    Amount::fromDecimal(
                        NumberParser::parseOrZero($row['tax_paid'] ?? '', decimalComma: true),
                        $currency,
                    ),
                    sprintf('%s (%s)', $source->name, CsvFormat::NormalizedDividends->label()),
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, $e->getMessage(), $line);
            }
        }

        foreach (array_keys($unknownCountries) as $country) {
            $messages[] = ImportMessage::warning(
                $source->name,
                sprintf(
                    'Nieznany kod kraju "%s" - brak skonfigurowanej stawki umownej; sposób odliczenia wymaga weryfikacji.',
                    $country,
                ),
            );
        }

        return new ImportResult([], $dividends, $messages);
    }
}

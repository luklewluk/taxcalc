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
use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Tax\TaxRates;

/**
 * Reads the tool's own closed-position format, unchanged since the CLI-only
 * version so existing files keep working:
 *
 *   name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount
 */
final class NormalizedPositionsImporter extends AbstractCsvImporter
{
    private const array REQUIRED = ['name', 'country', 'currency', 'buy_date', 'buy_total_amount', 'sell_date', 'sell_total_amount'];

    public function __construct(
        private readonly TaxRates $taxRates = new TaxRates(),
        int $maxRowsPerFile = self::DEFAULT_MAX_ROWS_PER_FILE,
    ) {
        parent::__construct($maxRowsPerFile);
    }

    public function supports(CsvFormat $format): bool
    {
        return CsvFormat::NormalizedPositions === $format;
    }

    public function import(CsvSource $source): ImportResult
    {
        [$rows, $fatal, $notices] = $this->readRows($source, self::REQUIRED);
        if ([] !== $fatal) {
            return new ImportResult([], [], $fatal);
        }

        $positions = [];
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

                $buyDate = DateParser::parse($row['buy_date'] ?? '');
                $sellDate = DateParser::parse($row['sell_date'] ?? '');

                if ($sellDate < $buyDate) {
                    $messages[] = ImportMessage::error(
                        $source->name,
                        sprintf('Data sprzedaży (%s) jest wcześniejsza niż data zakupu (%s).',
                            $sellDate->format('Y-m-d'), $buyDate->format('Y-m-d')),
                        $line,
                    );

                    continue;
                }

                $country = CountryCode::normalizeOptional($row['country'] ?? '');
                if ('' !== $country && !$this->taxRates->isKnownCountry($country)) {
                    $unknownCountries[$country] = true;
                }

                $positions[] = new ClosedPosition(
                    $name,
                    $country,
                    $currency,
                    $buyDate,
                    Amount::fromDecimal(NumberParser::parse($row['buy_total_amount'] ?? '', decimalComma: true), $currency),
                    $sellDate,
                    Amount::fromDecimal(NumberParser::parse($row['sell_total_amount'] ?? '', decimalComma: true), $currency),
                    null,
                    sprintf('%s (%s)', $source->name, CsvFormat::NormalizedPositions->label()),
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, $e->getMessage(), $line);
            }
        }

        foreach (array_keys($unknownCountries) as $country) {
            $messages[] = ImportMessage::warning(
                $source->name,
                sprintf('Nieznany kod kraju "%s" - sprawdź, czy to poprawny dwuliterowy kod ISO.', $country),
            );
        }

        return new ImportResult($positions, [], $messages);
    }
}

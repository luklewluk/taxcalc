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
use App\Model\Dividend;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;

/**
 * Reads the flat Interactive Brokers activity export:
 *
 *   CurrencyPrimary,Symbol,Multiplier,Date/Time,Amount,Type,TransactionID
 *
 * Dividend rows carry the gross amount. When the same export also contains
 * "Withholding Tax" rows, they are matched back onto the dividend they belong
 * to by symbol, currency and date, so the tax paid at source is not lost.
 *
 * The format carries no country of origin - use the Dividend Detail export
 * (tax documents) if you want it filled in automatically.
 */
final class IbkrActivityDividendsImporter extends AbstractCsvImporter
{
    private const array REQUIRED = ['currencyprimary', 'symbol', 'date/time', 'amount', 'type'];

    /**
     * @var list<string>
     */
    private const array DIVIDEND_TYPES = ['dividends', 'payment in lieu of dividends'];

    /**
     * @var list<string>
     */
    private const array WITHHOLDING_TYPES = ['withholding tax'];

    public function supports(CsvFormat $format): bool
    {
        return CsvFormat::IbkrActivityDividends === $format;
    }

    public function import(CsvSource $source): ImportResult
    {
        [$rows, $fatal, $notices] = $this->readRows($source, self::REQUIRED);
        if ([] !== $fatal) {
            return new ImportResult([], [], $fatal);
        }

        $messages = $notices;
        $skippedTypes = [];

        /** @var array<string, array{string, string, DateTimeImmutable, Decimal}> $gross keyed by symbol|currency|date */
        $gross = [];
        /** @var array<string, Decimal> $withheld */
        $withheld = [];
        /** @var array<string, int> $withheldLines */
        $withheldLines = [];

        foreach ($rows as $index => $row) {
            $line = self::lineNumber($index);
            $type = mb_strtolower(trim($row['type'] ?? ''));

            $isDividend = in_array($type, self::DIVIDEND_TYPES, true);
            $isWithholding = in_array($type, self::WITHHOLDING_TYPES, true);

            if (!$isDividend && !$isWithholding) {
                if ('' !== $type) {
                    $skippedTypes[$row['type']] = ($skippedTypes[$row['type']] ?? 0) + 1;
                }

                continue;
            }

            try {
                $currency = strtoupper($row['currencyprimary'] ?? '');
                $symbol = trim($row['symbol'] ?? '');
                $date = DateParser::parse($row['date/time'] ?? '');
                $amount = NumberParser::parse($row['amount'] ?? '');

                if ('' === $symbol) {
                    $messages[] = ImportMessage::error($source->name, 'Brak symbolu instrumentu.', $line);

                    continue;
                }

                // Validate the currency here so a bad code is a row error.
                Amount::fromDecimal($amount, $currency);

                $key = $symbol.'|'.$currency.'|'.$date->format('Y-m-d');

                if ($isDividend) {
                    if (isset($gross[$key])) {
                        $gross[$key][3] = $gross[$key][3]->plus($amount);
                    } else {
                        $gross[$key] = [$symbol, $currency, $date, $amount];
                    }

                    continue;
                }

                // IBKR tax charges are negative and refunds/adjustments positive.
                // Preserve the sign and net all rows for the payment before
                // converting the final charge to a positive magnitude.
                $withheld[$key] = ($withheld[$key] ?? Decimal::zero())->plus($amount);
                $withheldLines[$key] = $line;
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, $e->getMessage(), $line);
            }
        }

        foreach ($skippedTypes as $type => $count) {
            $messages[] = ImportMessage::info(
                $source->name,
                sprintf('Pominięto %d wiersz(y) typu "%s" - nie są to dywidendy.', $count, $type),
            );
        }

        $dividends = [];
        foreach ($gross as $key => [$symbol, $currency, $date, $amount]) {
            // Rows for one payment are summed before the record is built, so a
            // reversal that nets out negative only shows up here.
            try {
                $netWithholding = $withheld[$key] ?? Decimal::zero();
                if ($netWithholding->isPositive()) {
                    throw new InvalidRecordException(sprintf(
                        'Podatek u źródła ma dodatnie saldo %s %s (zwrot większy niż pobranie). Zweryfikuj zestawienie.',
                        (string) $netWithholding,
                        $currency,
                    ));
                }

                $dividends[] = new Dividend(
                    $symbol,
                    '',
                    $currency,
                    $date,
                    Amount::fromDecimal($amount, $currency),
                    Amount::fromDecimal($netWithholding->abs(), $currency),
                    sprintf('%s (%s)', $source->name, CsvFormat::IbkrActivityDividends->label()),
                );
            } catch (InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, sprintf(
                    'Dywidenda %s z dnia %s: %s',
                    $symbol,
                    $date->format('Y-m-d'),
                    $e->getMessage(),
                ));
            }

            unset($withheld[$key]);
        }

        foreach ($withheld as $key => $amount) {
            [$symbol, , $date] = explode('|', $key);
            $messages[] = ImportMessage::warning(
                $source->name,
                sprintf(
                    'Podatek u źródła %s dla %s z dnia %s nie pasuje do żadnej dywidendy w tym pliku - pominięto.',
                    (string) $amount,
                    $symbol,
                    $date,
                ),
                $withheldLines[$key] ?? null,
            );
        }

        if ([] !== $dividends) {
            $messages[] = ImportMessage::warning(
                $source->name,
                'Ten format nie zawiera kraju uzyskania dochodu - uzupełnij kolumnę "Kraj" przed obliczeniem '
                .'albo użyj zestawienia "Dividend Detail" z sekcji dokumentów podatkowych.',
            );
        }

        return new ImportResult([], $dividends, $messages);
    }
}

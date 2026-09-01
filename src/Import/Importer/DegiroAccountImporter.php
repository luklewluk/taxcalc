<?php

declare(strict_types=1);

namespace App\Import\Importer;

use App\Exception\InvalidCurrencyException;
use App\Exception\InvalidDateException;
use App\Exception\InvalidNumberException;
use App\Exception\InvalidRecordException;
use App\Import\CsvFormat;
use App\Import\CsvSource;
use App\Import\Degiro\DegiroCashRow;
use App\Import\Degiro\DegiroCsvReader;
use App\Import\Degiro\DegiroHeader;
use App\Import\Degiro\Isin;
use App\Import\ImportMessage;
use App\Import\ImportResult;
use App\Import\Parser\DateParser;
use App\Import\Parser\NumberParser;
use App\Model\Dividend;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;

/**
 * Reads the DEGIRO *Account statement* (Rekeningoverzicht / zestawienie konta),
 * which is where dividends and the tax withheld on them are recorded.
 *
 * Only dividends are taken from this file. It also lists every buy and sell, but
 * the Transactions export is the canonical record of those - it states the
 * quantity, the price and the order ID, none of which appear here - so importing
 * trades from both files would double every position. Trade rows are counted and
 * reported, never settled.
 *
 * Four details of the format drive the implementation:
 *
 *  - The 12 columns include duplicated *blank* headers, because the currency of
 *    an amount is written in the unnamed column beside it. Rows therefore have
 *    to be read positionally; see {@see DegiroCsvReader}.
 *  - The *value date* decides the tax year, not the booking date. A dividend
 *    booked on 2 January with a value date of 29 December belongs to the earlier
 *    year, and using the wrong one moves income between tax returns.
 *  - Descriptions are localized, and every language spells its withholding tax
 *    as a variation on the word "dividend" (`Dividendbelasting`,
 *    `Podatek od dywidendy`). Tax patterns are therefore matched *before* the
 *    generic dividend one; the other order would book withholding as income.
 *  - A payment is *two rows* - the dividend and the tax withheld on it - and
 *    they need not sit in the same export. Someone downloading month by month,
 *    or re-downloading an overlapping period, easily separates them. So this is
 *    a {@see BatchImporterInterface}: every statement is parsed on its own, with
 *    its own header, language and number notation, but payments are assembled
 *    across the whole upload. Aggregating per file reported the withholding as
 *    zero and overstated the tax due.
 */
final class DegiroAccountImporter implements BatchImporterInterface
{
    /**
     * Withholding-tax descriptions, matched before {@see DIVIDEND_MARKERS}.
     *
     * @var list<string>
     */
    private const array TAX_MARKERS = [
        'dividend tax', 'dividendtax', 'withholding tax', 'withholdingtax',
        'dividendbelasting',
        'podatek od dywidendy', 'podatek od dywidend', 'podatek u źródła', 'podatek u zrodla',
        'impôts sur dividende', 'impots sur dividende', 'retenue à la source', 'retenue a la source',
        'retención del dividendo', 'retencion del dividendo',
        'quellensteuer', 'dividendensteuer', 'kapitalertragsteuer',
        'imposta sul dividendo', 'ritenuta sul dividendo',
    ];

    /**
     * Dividend descriptions. Stems rather than whole words, so `Dividende`,
     * `Dividendo`, `Dywidenda` and `Dywidendy` are all covered.
     *
     * @var list<string>
     */
    private const array DIVIDEND_MARKERS = ['dividend', 'dywidend', 'dividendi'];

    /**
     * Descriptions of buys and sells. Only used to tell the user which file to
     * upload for trades - never to build a position.
     *
     * @var list<string>
     */
    private const array TRADE_MARKERS = [
        'buy', 'sell', 'koop', 'verkoop', 'kup', 'sprzeda', 'compra', 'venta', 'kauf', 'verkauf', 'achat', 'vente',
    ];

    public function __construct(
        private readonly int $maxRowsPerFile = AbstractCsvImporter::DEFAULT_MAX_ROWS_PER_FILE,
    ) {
    }

    public function supports(CsvFormat $format): bool
    {
        return CsvFormat::DegiroAccount === $format;
    }

    public function import(CsvSource $source): ImportResult
    {
        return $this->importMany([$source]);
    }

    public function importMany(array $sources): ImportResult
    {
        $messages = [];
        /** @var list<DegiroCashRow> $rows */
        $rows = [];
        $skipped = 0;
        $skippedTrades = 0;

        foreach ($sources as $source) {
            [$sourceRows, $sourceMessages, $sourceSkipped, $sourceTrades] = $this->readSource($source);

            $rows = [...$rows, ...$sourceRows];
            $messages = [...$messages, ...$sourceMessages];
            $skipped += $sourceSkipped;
            $skippedTrades += $sourceTrades;
        }

        [$rows, $duplicates] = self::deduplicate($rows);

        /** @var array<string, array{string, string, string, DateTimeImmutable, Decimal}> $gross */
        $gross = [];
        /** @var array<string, Decimal> $withheld */
        $withheld = [];
        /** @var array<string, array{string, int|null}> $withheldOrigin */
        $withheldOrigin = [];
        /** @var array<string, string> $grossSource */
        $grossSource = [];

        foreach ($rows as $row) {
            if ($row->isTax) {
                // Charges are negative and refunds positive. The sign is kept
                // until every row of the payment has been netted, so a refund
                // reduces the tax paid instead of adding to it.
                $withheld[$row->paymentKey] = ($withheld[$row->paymentKey] ?? Decimal::zero())->plus($row->amount);
                $withheldOrigin[$row->paymentKey] = [$row->source, $row->line];

                continue;
            }

            if (isset($gross[$row->paymentKey])) {
                $gross[$row->paymentKey][4] = $gross[$row->paymentKey][4]->plus($row->amount);

                continue;
            }

            $gross[$row->paymentKey] = [$row->name, $row->isin, $row->currency, $row->date, $row->amount];
            $grossSource[$row->paymentKey] = $row->source;
        }

        $dividends = [];
        $inferredCountry = false;
        $unknownCountry = false;

        foreach ($gross as $key => [$name, $isin, $currency, $date, $amount]) {
            $country = Isin::country($isin);

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
                    $name,
                    $country,
                    $currency,
                    $date,
                    Amount::fromDecimal($amount, $currency),
                    Amount::fromDecimal($netWithholding->abs(), $currency),
                    sprintf('%s (%s)', $grossSource[$key] ?? 'Import', CsvFormat::DegiroAccount->label()),
                );

                '' === $country ? $unknownCountry = true : $inferredCountry = true;
            } catch (InvalidRecordException $e) {
                $messages[] = ImportMessage::error($grossSource[$key] ?? 'Import', sprintf(
                    'Dywidenda %s z dnia %s: %s',
                    $name,
                    $date->format('Y-m-d'),
                    $e->getMessage(),
                ));
            }

            unset($withheld[$key]);
        }

        // Only now, with every uploaded statement read, is a leftover tax row
        // genuinely orphaned rather than merely booked in another file.
        foreach ($withheld as $key => $amount) {
            [$origin, $line] = $withheldOrigin[$key] ?? ['Import', null];

            $messages[] = ImportMessage::error(
                $origin,
                sprintf(
                    'Podatek u źródła %s dla %s nie pasuje do żadnej dywidendy w żadnym z wgranych plików. '
                    .'Brakuje wypłaty brutto, więc cały import przerwano; dograj pełne zestawienie konta.',
                    (string) $amount,
                    str_replace('|', ' / ', $key),
                ),
                $line,
            );
        }

        if ($duplicates > 0) {
            $messages[] = ImportMessage::info('Import', sprintf(
                'Pominięto %d powtórzon(y/e) wiersz(e) występując(y/e) w kilku zestawieniach konta.',
                $duplicates,
            ));
        }

        if ($skipped > 0) {
            $messages[] = ImportMessage::info('Import', sprintf(
                'Pominięto %d wiersz(y), które nie są dywidendą ani podatkiem u źródła '
                .'(np. wpłaty, wypłaty, opłaty, przewalutowania, odsetki).',
                $skipped,
            ));
        }

        if ($skippedTrades > 0) {
            $messages[] = ImportMessage::info('Import', sprintf(
                'W tym %d wiersz(y) kupna/sprzedaży - zestawienie konta nie podaje liczby sztuk ani kursu, '
                .'więc transakcje wczytuj z eksportu "Transakcje" (Transactions).',
                $skippedTrades,
            ));
        }

        if ($inferredCountry) {
            $messages[] = ImportMessage::warning(
                'Import',
                'Kraj uzyskania dochodu został ustalony z dwóch pierwszych znaków numeru ISIN. '
                .'To kraj rejestracji papieru, a nie zawsze kraj źródła dochodu - sprawdź kolumnę "Kraj" '
                .'przed obliczeniem, zwłaszcza dla ETF-ów i spółek notowanych poza krajem rejestracji.',
            );
        }

        if ($unknownCountry) {
            $messages[] = ImportMessage::warning(
                'Import',
                'Dla części dywidend nie dało się ustalić kraju z numeru ISIN. Uzupełnij kolumnę "Kraj" '
                .'ręcznie przed obliczeniem.',
            );
        }

        return new ImportResult([], $dividends, $messages);
    }

    /**
     * Reads one statement on its own: its own header, language and notation.
     *
     * @return array{list<DegiroCashRow>, list<ImportMessage>, int, int} rows,
     *                                                                  messages, skipped rows and, of those, buy/sell rows
     */
    private function readSource(CsvSource $source): array
    {
        [$table, $messages] = DegiroCsvReader::read($source, $this->maxRowsPerFile);
        if (null === $table) {
            return [[], $messages, 0, 0];
        }

        $header = $table->header;

        $descriptionIndex = $header->indexOf(DegiroHeader::DESCRIPTION);
        // The value date is what the tax year is taken from; the booking date is
        // only a fallback for the rare export that omits it.
        $dateIndex = $header->indexOf(DegiroHeader::VALUE_DATE) ?? $header->indexOf(DegiroHeader::DATE);
        $money = self::locateMoney($header);

        $missing = [];
        if (null === $descriptionIndex) {
            $missing[] = 'opis';
        }
        if (null === $dateIndex) {
            $missing[] = 'data waluty';
        }
        if (null === $money) {
            $missing[] = 'kwota/zmiana salda';
        }

        if (null === $descriptionIndex || null === $dateIndex || null === $money) {
            return [[], [...$messages, ImportMessage::error(
                $source->name,
                sprintf('Brakuje wymaganych kolumn: %s.', implode(', ', $missing)),
            )], 0, 0];
        }

        [$amountIndex, $currencyIndex, $headerCurrency] = $money;

        $productIndex = $header->indexOf(DegiroHeader::PRODUCT);
        $isinIndex = $header->indexOf(DegiroHeader::ISIN);

        $rows = [];
        $skipped = 0;
        $skippedTrades = 0;

        /** @var array<string, int> $ordinals occurrences of each identical row in this file */
        $ordinals = [];

        foreach ($table->rows as [$line, $row]) {
            $description = DegiroHeader::normalize($table->value($row, $descriptionIndex));

            $isTax = self::matches($description, self::TAX_MARKERS);
            $isDividend = !$isTax && self::matches($description, self::DIVIDEND_MARKERS);

            if (!$isTax && !$isDividend) {
                ++$skipped;
                if (self::matches($description, self::TRADE_MARKERS)) {
                    ++$skippedTrades;
                }

                continue;
            }

            try {
                $isin = mb_strtoupper($table->value($row, $isinIndex));
                $product = $table->value($row, $productIndex);

                if ('' !== $isin && !Isin::isWellFormed($isin)) {
                    $messages[] = ImportMessage::error(
                        $source->name,
                        sprintf('Numer ISIN "%s" ma nieprawidłową postać.', $isin),
                        $line,
                    );

                    continue;
                }

                if ('' === $isin && '' === $product) {
                    $messages[] = ImportMessage::error(
                        $source->name,
                        'Wiersz dywidendy bez numeru ISIN i bez nazwy instrumentu - nie da się go przypisać '
                        .'do żadnej wypłaty.',
                        $line,
                    );

                    continue;
                }

                $currency = $headerCurrency ?? mb_strtoupper($table->value($row, $currencyIndex));
                $amount = NumberParser::parse($table->value($row, $amountIndex), $table->decimalComma());

                // Validated here so a shifted column is a row error rather than
                // an amount silently tagged with the wrong currency.
                Amount::zero($currency);

                $date = DateParser::parse($table->value($row, $dateIndex));
                $key = ('' === $isin ? 'name:'.mb_strtoupper($product) : $isin)
                    .'|'.$currency.'|'.$date->format('Y-m-d');

                $identity = ($isTax ? 'tax' : 'gross').'|'.$key.'|'.$amount;
                $ordinal = $ordinals[$identity] = ($ordinals[$identity] ?? 0) + 1;

                $rows[] = new DegiroCashRow(
                    $isTax,
                    $key,
                    '' === $product ? $isin : $product,
                    $isin,
                    $currency,
                    $date,
                    $amount,
                    $source->name,
                    $line,
                    $ordinal,
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, $e->getMessage(), $line);
            }
        }

        return [$rows, $messages, $skipped, $skippedTrades];
    }

    /**
     * Drops rows that the same period, exported twice, reported twice.
     *
     * A row is identified by what it says plus its position among identical rows
     * *in its own file*, so an overlapping export loses its repeats while two
     * genuine identical payments booked on one day both survive.
     *
     * @param list<DegiroCashRow> $rows
     *
     * @return array{list<DegiroCashRow>, int} the rows kept and how many were dropped
     */
    private static function deduplicate(array $rows): array
    {
        $seen = [];
        $kept = [];
        $duplicates = 0;

        foreach ($rows as $row) {
            $signature = $row->signature();

            if (isset($seen[$signature])) {
                ++$duplicates;

                continue;
            }

            $seen[$signature] = true;
            $kept[] = $row;
        }

        return [$kept, $duplicates];
    }

    /**
     * Locates the amount column and the column holding its currency.
     *
     * Current statements name the *balance change* column, which holds the
     * currency, with the amount in the unnamed column after it. Older ones name
     * the amount column instead, with the currency immediately before it.
     *
     * @return array{int, int, string|null}|null amount index, currency index and
     *                                           the currency named in the header, if any
     */
    private static function locateMoney(DegiroHeader $header): ?array
    {
        $change = $header->indexOfAmount(DegiroHeader::CHANGE);
        if (null !== $change) {
            return [$change[0] + 1, $change[0], $change[1]];
        }

        $amount = $header->indexOfAmount(DegiroHeader::AMOUNT);
        if (null !== $amount) {
            return [$amount[0], max(0, $amount[0] - 1), $amount[1]];
        }

        return null;
    }

    /**
     * @param list<string> $markers
     */
    private static function matches(string $description, array $markers): bool
    {
        if ('' === $description) {
            return false;
        }

        foreach ($markers as $marker) {
            if (str_contains($description, $marker)) {
                return true;
            }
        }

        return false;
    }
}

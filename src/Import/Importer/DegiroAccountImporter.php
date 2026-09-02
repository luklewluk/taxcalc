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
use App\Model\AccountFee;
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
 *  - The *booking date* decides the tax year, not the value date. Income exists
 *    on the day it is received or placed at the taxpayer's disposal (art. 11
 *    ust. 1) and the NBP rate is the last business day before it (art. 11a);
 *    DEGIRO books a dividend only once the custodian confirms the cash, so the
 *    value date is the issuer's payable date and proves nothing about
 *    availability. Using the wrong one moves income between tax returns. Where
 *    a group spans several bookings, the *earliest* is the day the cash first
 *    landed and the later ones are corrections of it.
 *  - Payments are grouped by instrument, currency, value date **and the year of
 *    the booking date**. The value date says which payment a row describes, so
 *    a reversal and its re-post net against the original. The booking year
 *    keeps a reversal posted in a later year from reaching back and emptying
 *    the year the original was settled in; such a group is a reversal on its
 *    own and is reported instead of settled.
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
        'podatek od dywidendy', 'podatek od dywidend', 'podatek dywidendowy',
        'podatek u źródła', 'podatek u zrodla',
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

    /** Product substitutions are account movements, not cash dividends. */
    private const array PRODUCT_CHANGE_MARKERS = [
        'product change', 'product change correction', 'zmiana produktu', 'korekta zmiany produktu',
        'productwijziging', 'produktänderung', 'produktaenderung', 'cambio de producto',
    ];

    /** Distributions whose tax treatment cannot be inferred from this export. */
    private const array UNSUPPORTED_DISTRIBUTIONS = [
        'capital return', 'qie distribution capital gain',
    ];

    /** Exact normalized descriptions accepted as standalone account charges. */
    private const array CONNECTION_FEE_DESCRIPTIONS = [
        'degiro exchange connection fee',
        'degiro exchange connection fee correction',
        'degiro exchange connection fee (correction)',
        'degiro exchange connection fee refund',
        'degiro aansluitingskosten beurs',
        'degiro börsenanschlussgebühr',
        'degiro borsenanschlussgebuhr',
        'degiro opłata za połączenie z giełdą',
        'degiro oplata za polaczenie z gielda',
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
        /** @var list<AccountFee> $fees */
        $fees = [];

        foreach ($sources as $source) {
            [$sourceRows, $sourceFees, $sourceMessages, $sourceSkipped, $sourceTrades] = $this->readSource($source);

            $rows = [...$rows, ...$sourceRows];
            $fees = [...$fees, ...$sourceFees];
            $messages = [...$messages, ...$sourceMessages];
            $skipped += $sourceSkipped;
            $skippedTrades += $sourceTrades;
        }

        [$rows, $duplicates] = self::deduplicate($rows);
        [$fees, $feeDuplicates] = self::deduplicateFees($fees);

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
                // The day the cash first landed is the day the income arose;
                // later bookings in the group are corrections of it.
                if ($row->date < $gross[$row->paymentKey][3]) {
                    $gross[$row->paymentKey][3] = $row->date;
                }

                continue;
            }

            $gross[$row->paymentKey] = [$row->name, $row->isin, $row->currency, $row->date, $row->amount, $row->valueDate];
            $grossSource[$row->paymentKey] = $row->source;
        }

        $dividends = [];
        $inferredCountry = false;
        $unknownCountry = false;
        $zeroCorrections = 0;
        /** @var list<string> $reversals */
        $reversals = [];

        foreach ($gross as $key => $payment) {
            [$name, $isin, $currency, $date, $amount] = $payment;
            $valueDate = $payment[5] ?? null;
            $country = Isin::country($isin);

            try {
                $netWithholding = $withheld[$key] ?? Decimal::zero();

                if ($amount->isNegative()) {
                    // A reversal of an earlier payment, posted on its own: the
                    // cash moved now, but it is not income now. `Dividend`
                    // refuses a non-positive gross, so building one here would
                    // fail the whole batch on an ordinary statement - and
                    // subtracting it from this year would be wrong anyway,
                    // because it corrects the year the payment was settled in.
                    $reversals[] = sprintf(
                        '%s (%s %s, zaksięgowano %s%s)',
                        $name,
                        (string) $amount,
                        $currency,
                        $date->format('Y-m-d'),
                        null === $valueDate ? '' : ', koryguje wypłatę z '.$valueDate->format('Y'),
                    );
                    unset($withheld[$key]);

                    continue;
                }

                if ($amount->isZero()) {
                    if (!$netWithholding->isZero()) {
                        throw new InvalidRecordException(sprintf(
                            'Kwota brutto po korektach wynosi zero, ale podatek u źródła ma saldo %s %s. '
                            .'Taka grupa jest niespójna i wymaga ręcznej weryfikacji.',
                            (string) $netWithholding,
                            $currency,
                        ));
                    }

                    ++$zeroCorrections;
                    unset($withheld[$key]);

                    continue;
                }

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

        if ([] !== $reversals) {
            $listed = array_slice($reversals, 0, 10);
            $messages[] = ImportMessage::review('Import', sprintf(
                'Pominięto %d storn(o/a) wcześniejszych wypłat: %s%s. Ujemna wypłata nie jest '
                .'przychodem bieżącego roku - koryguje rok, w którym rozliczono pierwotną wypłatę, '
                .'więc rozlicz ją tam, a nie odejmuj od tego roku.',
                count($reversals),
                implode('; ', $listed),
                count($reversals) > count($listed) ? sprintf(' i %d innych', count($reversals) - count($listed)) : '',
            ))->forTab('dividends');
        }

        if ($zeroCorrections > 0) {
            $messages[] = ImportMessage::info('Import', sprintf(
                'Pominięto %d grup(ę/y) wypłat odwróconych w całości: po zsumowaniu korekt zarówno brutto, '
                .'jak i podatek wynoszą zero.',
                $zeroCorrections,
            ));
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

        if ($feeDuplicates > 0) {
            $messages[] = ImportMessage::info('Import', sprintf(
                'Pominięto %d powtórzon(ą/e) opłat(ę/y) z nakładających się zestawień konta.',
                $feeDuplicates,
            ));
        }

        [$fees, $feeMessages] = self::validateFeeGroups($fees);
        $messages = [...$messages, ...$feeMessages];

        if ($skipped > 0) {
            $messages[] = ImportMessage::info('Import', sprintf(
                'Pominięto %d wiersz(y), które nie są dywidendą ani podatkiem u źródła '
                .'(np. wpłaty, wypłaty, przewalutowania, odsetki).',
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

        return new ImportResult([], $dividends, $messages, [], $fees);
    }

    /**
     * Reads one statement on its own: its own header, language and notation.
     *
     * @return array{list<DegiroCashRow>, list<AccountFee>, list<ImportMessage>, int, int}
     */
    private function readSource(CsvSource $source): array
    {
        [$table, $messages] = DegiroCsvReader::read($source, $this->maxRowsPerFile);
        if (null === $table) {
            return [[], [], $messages, 0, 0];
        }

        $header = $table->header;

        $descriptionIndex = $header->indexOf(DegiroHeader::DESCRIPTION);
        // The booking date settles the year: income exists on the day the money
        // is received or placed at the taxpayer's disposal (art. 11 ust. 1), and
        // DEGIRO books a dividend only once the custodian confirms the cash. The
        // value date is the issuer's payable date - it does not prove the money
        // was available - so it is only a fallback for an export that omits the
        // booking column, plus audit data for reversal messages.
        //
        // Current Polish statements label both columns simply `Data`: the first
        // is the booking date and the second the value date.
        $dateIndexes = $header->indexesOf(DegiroHeader::DATE);
        $valueDateIndex = $header->indexOf(DegiroHeader::VALUE_DATE) ?? ($dateIndexes[1] ?? null);
        $dateIndex = $dateIndexes[0] ?? $valueDateIndex;
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
            return [[], [], [...$messages, ImportMessage::error(
                $source->name,
                sprintf('Brakuje wymaganych kolumn: %s.', implode(', ', $missing)),
            )], 0, 0];
        }

        [$amountIndex, $currencyIndex, $headerCurrency] = $money;

        $productIndex = $header->indexOf(DegiroHeader::PRODUCT);
        $isinIndex = $header->indexOf(DegiroHeader::ISIN);

        $rows = [];
        $fees = [];
        $skipped = 0;
        $skippedTrades = 0;
        $unsupported = [];

        /** @var array<string, int> $ordinals occurrences of each identical row in this file */
        $ordinals = [];

        foreach ($table->rows as [$line, $row]) {
            $description = DegiroHeader::normalize($table->value($row, $descriptionIndex));

            $isTax = self::matches($description, self::TAX_MARKERS);
            $isConnectionFee = in_array($description, self::CONNECTION_FEE_DESCRIPTIONS, true);

            if ($isConnectionFee) {
                try {
                    $currency = self::normalizeCurrency($headerCurrency ?? mb_strtoupper($table->value($row, $currencyIndex)));
                    $signedAmount = NumberParser::parseLocalized($table->value($row, $amountIndex), $table->decimalComma());
                    Amount::zero($currency);
                    if ($signedAmount->isZero()) {
                        ++$skipped;
                        continue;
                    }

                    $date = DateParser::parse($table->value($row, $dateIndex));
                    $identity = implode('|', [$description, $date->format('Y-m-d'), $currency, (string) $signedAmount]);
                    $ordinal = $ordinals['fee|'.$identity] = ($ordinals['fee|'.$identity] ?? 0) + 1;
                    $fees[] = new AccountFee(
                        $table->value($row, $descriptionIndex),
                        'Połączenie z giełdą DEGIRO',
                        $date,
                        $currency,
                        Amount::fromDecimal($signedAmount->abs(), $currency),
                        $signedAmount->isPositive(),
                        sprintf('%s (%s)', $source->name, CsvFormat::DegiroAccount->label()),
                        hash('sha256', 'degiro-fee|'.$identity.'|'.$ordinal),
                    );
                } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                    $messages[] = ImportMessage::error($source->name, 'Opłata rachunkowa: '.$e->getMessage(), $line);
                }

                continue;
            }

            // These categories have priority over the broad `dividend` stem:
            // an ETF name in a buy description is not income, and a product
            // substitution is not a distribution.
            if (!$isTax && self::matches($description, self::TRADE_MARKERS)) {
                ++$skipped;
                ++$skippedTrades;

                continue;
            }

            if (!$isTax && self::matches($description, self::PRODUCT_CHANGE_MARKERS)) {
                ++$skipped;

                continue;
            }

            if (!$isTax && self::matches($description, self::UNSUPPORTED_DISTRIBUTIONS)) {
                ++$skipped;
                foreach (self::UNSUPPORTED_DISTRIBUTIONS as $marker) {
                    if (str_contains($description, $marker)) {
                        $unsupported[$marker] = true;
                    }
                }

                continue;
            }

            $isDividend = !$isTax && self::matches($description, self::DIVIDEND_MARKERS);

            if (!$isTax && !$isDividend) {
                ++$skipped;
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

                $currency = self::normalizeCurrency($headerCurrency ?? mb_strtoupper($table->value($row, $currencyIndex)));
                $amount = NumberParser::parseLocalized($table->value($row, $amountIndex), $table->decimalComma());

                // Validated here so a shifted column is a row error rather than
                // an amount silently tagged with the wrong currency.
                Amount::zero($currency);

                $date = DateParser::parse($table->value($row, $dateIndex));
                $rawValueDate = $table->value($row, $valueDateIndex);
                $valueDate = $valueDateIndex === $dateIndex || '' === $rawValueDate
                    ? null
                    : DateParser::parse($rawValueDate);
                // The value date identifies *which payment* a row describes, so
                // corrections keep netting against the payment they correct -
                // DEGIRO reverses one on one day and re-posts it on the next,
                // and keying on the booking date alone would settle both.
                //
                // The booking *year* is part of the key as well, because a
                // reversal posted in a later year must not reach back and empty
                // the year the original was settled in. Within one year
                // corrections net; across years the parts stay apart.
                $key = ('' === $isin ? 'name:'.mb_strtoupper($product) : $isin)
                    .'|'.$currency
                    .'|'.($valueDate ?? $date)->format('Y-m-d')
                    .'|'.$date->format('Y');

                $identity = ($isTax ? 'tax' : 'gross').'|'.$key.'|'.$amount;
                $ordinal = $ordinals[$identity] = ($ordinals[$identity] ?? 0) + 1;

                $rows[] = new DegiroCashRow(
                    $isTax,
                    $key,
                    '' === $product ? $isin : $product,
                    $isin,
                    $currency,
                    $date,
                    $valueDate,
                    $amount,
                    $source->name,
                    $line,
                    $ordinal,
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, $e->getMessage(), $line);
            }
        }

        if ([] !== $unsupported) {
            $messages[] = ImportMessage::warning(
                $source->name,
                sprintf(
                    'Pominięto wypłaty typu %s. Kalkulator nie ustala automatycznie ich skutków podatkowych; '
                    .'sprawdź je i rozlicz ręcznie.',
                    implode(' oraz ', array_map(static fn (string $name): string => '"'.$name.'"', array_keys($unsupported))),
                ),
            );
        }

        return [$rows, $fees, $messages, $skipped, $skippedTrades];
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
     * @param list<AccountFee> $fees
     *
     * @return array{list<AccountFee>, int}
     */
    private static function deduplicateFees(array $fees): array
    {
        $seen = [];
        $kept = [];
        $duplicates = 0;
        foreach ($fees as $fee) {
            if (isset($seen[$fee->id()])) {
                ++$duplicates;
                continue;
            }
            $seen[$fee->id()] = true;
            $kept[] = $fee;
        }

        return [$kept, $duplicates];
    }

    /**
     * Net strict connection-fee corrections per tax year and currency. A fully
     * reversed group disappears; a net refund is unsafe and fails the batch.
     *
     * @param list<AccountFee> $fees
     * @return array{list<AccountFee>, list<ImportMessage>}
     */
    private static function validateFeeGroups(array $fees): array
    {
        /** @var array<string, array{Decimal, Decimal, list<int>}> $groups */
        $groups = [];
        foreach ($fees as $index => $fee) {
            $key = $fee->taxYear().'|'.$fee->currency.'|'.$fee->category;
            $groups[$key] ??= [Decimal::zero(), Decimal::zero(), []];
            $slot = $fee->correction ? 1 : 0;
            $groups[$key][$slot] = $groups[$key][$slot]->plus($fee->amount->value());
            $groups[$key][2][] = $index;
        }

        $drop = [];
        $messages = [];
        foreach ($groups as $key => [$charges, $corrections, $indexes]) {
            $comparison = $corrections->compareTo($charges);
            if (0 === $comparison && !$charges->isZero()) {
                foreach ($indexes as $index) {
                    $drop[$index] = true;
                }
                $messages[] = ImportMessage::info('Import', sprintf(
                    'Pominięto wyzerowaną grupę opłat rachunkowych %s — korekty w całości odwracają opłaty.',
                    str_replace('|', ' / ', $key),
                ));
            } elseif ($comparison > 0) {
                $messages[] = ImportMessage::error('Import', sprintf(
                    'Zwroty opłat rachunkowych %s przewyższają pierwotne opłaty. Rozliczenie tej grupy zatrzymano; '
                    .'popraw wpisy ręcznie.',
                    str_replace('|', ' / ', $key),
                ));
            }
        }

        return [array_values(array_filter(
            $fees,
            static fn (AccountFee $fee, int $index): bool => !isset($drop[$index]),
            ARRAY_FILTER_USE_BOTH,
        )), $messages];
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

    private static function normalizeCurrency(string $currency): string
    {
        return match ($currency) {
            'NO' => 'NOK',
            'SG' => 'SGD',
            default => $currency,
        };
    }
}

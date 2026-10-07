<?php

declare(strict_types=1);

namespace App\Import\Importer;

use App\Exception\InvalidCurrencyException;
use App\Exception\InvalidDateException;
use App\Exception\InvalidNumberException;
use App\Exception\InvalidRecordException;
use App\Fifo\FifoMatcher;
use App\Fifo\InstrumentDetails;
use App\Fifo\Trade;
use App\Fifo\UnmatchedSell;
use App\Import\CsvFormat;
use App\Import\CsvSource;
use App\Import\Degiro\DegiroCsvReader;
use App\Import\Degiro\DegiroHeader;
use App\Import\Degiro\DegiroTable;
use App\Import\Degiro\ExchangeCountry;
use App\Import\Degiro\Isin;
use App\Import\ImportMessage;
use App\Import\ImportResult;
use App\Import\Parser\DateParser;
use App\Import\Parser\NumberParser;
use App\Import\TradeIdScope;
use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Money\Decimal;

/**
 * Reads the DEGIRO *Transactions* export - the canonical record of buys and
 * sells. Dividends live in the separate account statement
 * ({@see DegiroAccountImporter}); this importer never produces them, and the
 * account statement never produces trades.
 *
 * The file exists in several shapes: 16 columns in the older export, ~19 in the
 * newer one, in whichever language the account is set to, comma- or
 * semicolon-separated, with English or continental decimals. Columns are
 * therefore located by localized alias and read *positionally*
 * ({@see DegiroCsvReader}), because the currency of each amount lives in the
 * unnamed column beside it.
 *
 * Money comes from the settled `Total` column, never from `Quantity * Price`.
 * That is the cash that actually left or entered the account, so it already
 * carries the transaction fee. On the buy leg that is exactly the acquisition
 * cost Polish rules want; on the sell leg the fee is added back to reach the
 * declared przychód and counted as a cost of disposal instead, in the tax layer
 * {@see \App\Tax\StockTaxCalculator}. Two consequences worth knowing:
 *
 *  - a total whose sign contradicts the quantity means the columns were misread,
 *    so the row is refused rather than settled;
 *  - DEGIRO's older exports are known not to include the AutoFX currency
 *    conversion fee in the exported totals. This importer says so when the file
 *    has no AutoFX column; it cannot invent a fee that was never exported.
 *
 * FIFO identity is the ISIN alone - not the product name, which changes when a
 * company is renamed, not the ticker, which differs between venues, and not the
 * settlement currency either: one ISIN is one holding even when fills settled in
 * different currencies, and splitting the queue per currency would leave a sale
 * uncovered and drop its gain from the return. A closed position can only carry
 * one currency, so a lot opened in a different currency than the sale that
 * closes it is refused with an explanation rather than settled in whichever
 * currency came first. The product name and the country inferred from the ISIN
 * travel alongside as display metadata ({@see InstrumentDetails}).
 *
 * The execution time is read too, but only to order the queue: two trades dated
 * the same day would otherwise be ordered by nothing more than which file was
 * uploaded first. It is dropped from the settled record, which is per day.
 */
final class DegiroTransactionsImporter implements TradeSourceImporterInterface
{
    public function __construct(
        private readonly FifoMatcher $fifoMatcher,
        private readonly int $maxRowsPerFile = AbstractCsvImporter::DEFAULT_MAX_ROWS_PER_FILE,
    ) {
    }

    public function supports(CsvFormat $format): bool
    {
        return CsvFormat::DegiroTransactions === $format;
    }

    public function tradeIdScope(): TradeIdScope
    {
        // One DEGIRO order can be executed in several rows.
        return TradeIdScope::Order;
    }

    public function tradeIdLabel(): string
    {
        return 'Order ID';
    }

    public function import(CsvSource $source): ImportResult
    {
        $extraction = $this->extractTrades($source);

        return $this->matchTrades($extraction->trades)->withMessages($extraction->messages);
    }

    public function extractTrades(CsvSource $source): TradeExtraction
    {
        [$table, $messages] = DegiroCsvReader::read($source, $this->maxRowsPerFile);
        if (null === $table) {
            return new TradeExtraction([], $messages);
        }

        $header = $table->header;

        $columns = [
            'data' => $header->indexOf(DegiroHeader::DATE),
            'ISIN' => $header->indexOf(DegiroHeader::ISIN),
            'liczba' => $header->indexOf(DegiroHeader::QUANTITY),
        ];

        $priceColumn = $header->indexOfAmount(DegiroHeader::PRICE);
        $total = $header->indexOfAmount(DegiroHeader::TOTAL);

        // A plain `Total`/`Razem` header stores its currency in the following
        // unnamed column. If Total is already the last column, every data row
        // would otherwise emit the same currency error; reject the malformed
        // layout once at header level instead.
        if (null !== $total && null === $total[1] && $total[0] + 1 >= $header->count()) {
            return new TradeExtraction([], [...$messages, ImportMessage::error(
                $source->name,
                'Kolumna razem (Total) nie określa waluty i nie ma obok kolumny walutowej.',
            )]);
        }

        $missing = array_keys($columns, null, true);
        if (null === $total) {
            $missing[] = 'razem (Total)';
        }
        if (null === $priceColumn) {
            $missing[] = 'cena (Price/Kurs)';
        }

        if ([] !== $missing) {
            return new TradeExtraction([], [...$messages, ImportMessage::error(
                $source->name,
                sprintf('Brakuje wymaganych kolumn: %s.', implode(', ', $missing)),
            )]);
        }

        /** @var array{int, string|null} $total */
        $productIndex = $header->indexOf(DegiroHeader::PRODUCT);
        $orderIndex = $header->indexOf(DegiroHeader::ORDER_ID);
        $timeIndex = $header->indexOf(DegiroHeader::TIME);
        $venueIndex = $header->indexOf(DegiroHeader::EXECUTION_VENUE);
        $referenceIndex = $header->indexOf(DegiroHeader::REFERENCE_EXCHANGE);
        $commissionColumn = $header->indexOfAmount(DegiroHeader::COSTS);
        $autoFxColumn = $header->indexOfAmount(DegiroHeader::AUTOFX);

        $trades = [];
        $skippedZero = 0;

        /**
         * Venue codes seen in this file that the table does not resolve to a
         * country, kept as keys so one message covers the whole file.
         *
         * @var array<string, true> $unresolvedVenues
         */
        $unresolvedVenues = [];
        $proposedFromExchange = false;
        $rowsWithoutVenue = 0;

        /**
         * Occurrences of each identical row seen so far in *this* file.
         *
         * @var array<string, int> $ordinals
         */
        $ordinals = [];

        foreach ($table->rows as [$line, $row]) {
            try {
                $isin = mb_strtoupper($table->value($row, $columns['ISIN']));
                if ('' === $isin) {
                    $messages[] = ImportMessage::error(
                        $source->name,
                        'Brak numeru ISIN - bez niego nie da się przypisać sprzedaży do zakupu.',
                        $line,
                    );

                    continue;
                }

                if (!Isin::isWellFormed($isin)) {
                    $messages[] = ImportMessage::error(
                        $source->name,
                        sprintf('Numer ISIN "%s" ma nieprawidłową postać.', $isin),
                        $line,
                    );

                    continue;
                }

                $decimalComma = $table->decimalComma();

                $quantity = NumberParser::parseLocalizedOrZero($table->value($row, $columns['liczba']), $decimalComma);
                if ($quantity->isZero()) {
                    ++$skippedZero;

                    continue;
                }

                $currency = self::currency($table, $row, $total);
                $amount = NumberParser::parseLocalizedOrZero($table->value($row, $total[0]), $decimalComma);
                $commission = self::optionalFee($table, $row, $commissionColumn, $currency, 'prowizja');
                $autoFx = self::optionalFee($table, $row, $autoFxColumn, $currency, 'AutoFX');

                if ($amount->isZero()) {
                    // A row that moves shares without moving cash is a corporate
                    // action - a split, a merger, a rights issue. Those change
                    // the quantity and the cost basis of everything that follows
                    // in the queue, which this calculator deliberately does not
                    // model. Carrying on would settle *later* sales of the same
                    // instrument against a lot that no longer exists as
                    // exported, so the whole batch stops here.
                    $messages[] = ImportMessage::error($source->name, sprintf(
                        'Wiersz %s (%s szt.) nie ma kursu ani kwoty - wygląda na operację korporacyjną '
                        .'(split, scalenie, przydział). Kalkulator nie rozlicza takich zdarzeń, a pominięcie '
                        .'tego wiersza zmieniłoby koszt kolejnych sprzedaży tego papieru. Usuń ten instrument '
                        .'z pliku i rozlicz go ręcznie.',
                        $isin,
                        (string) $quantity,
                    ), $line);

                    continue;
                }

                /** @var array{int, string|null} $priceColumn */
                $price = self::optionalPrice($table, $row, $priceColumn);

                self::assertSignsAgree($quantity, $amount, $currency);

                $date = DateParser::parseWithTime(
                    $table->value($row, $columns['data']),
                    $table->value($row, $timeIndex),
                );

                // Everything that makes this row the row it is. Two rows sharing
                // it are indistinguishable, so their order inside the file is
                // the only thing that separates them.
                $orderId = self::orderId($table, $row, $orderIndex);

                $signature = implode('|', [
                    $isin,
                    $currency,
                    $date->format('Y-m-d H:i:s'),
                    (string) $quantity,
                    (string) $amount,
                    null === $price ? '' : $price->currency().':'.$price->value(),
                    $orderId ?? '',
                ]);
                $ordinal = $ordinals[$signature] = ($ordinals[$signature] ?? 0) + 1;

                [$venueCode, $venueCountry] = self::resolveVenue(
                    $table->value($row, $referenceIndex),
                    $table->value($row, $venueIndex),
                );
                if ('' === $venueCode) {
                    ++$rowsWithoutVenue;
                } elseif ('' === $venueCountry) {
                    $unresolvedVenues[$venueCode] = true;
                } else {
                    $proposedFromExchange = true;
                }

                $trades[] = new Trade(
                    $isin,
                    $date,
                    $quantity,
                    Amount::fromDecimal($amount->abs(), $currency),
                    $orderId ?? self::syntheticId($signature),
                    sprintf('%s (%s)', $source->name, CsvFormat::DegiroTransactions->label()),
                    new InstrumentDetails(
                        $table->value($row, $productIndex) ?: $isin,
                        $venueCountry,
                        $venueCode,
                    ),
                    $ordinal,
                    null !== $orderId,
                    $price,
                    null === $venueIndex ? '[nieznana kolumna miejsca wykonania]' : $table->value($row, $venueIndex),
                    'DEGIRO',
                    $commission,
                    $autoFx,
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, $e->getMessage(), $line);
            }
        }

        [$trades, $neutralChanges, $correctionMessages] = self::removeNeutralProductChanges($trades, $source->name);
        $messages = [...$messages, ...$correctionMessages];

        if ($neutralChanges > 0) {
            $messages[] = ImportMessage::info($source->name, sprintf(
                'Pominięto %d neutraln(ą/e) zmian(ę/y) nazwy produktu (pary przeciwnych zapisów bez zmiany salda i liczby sztuk).',
                $neutralChanges,
            ));
        }

        if ($skippedZero > 0) {
            $messages[] = ImportMessage::info($source->name, sprintf(
                'Pominięto %d wiersz(y) z zerową liczbą sztuk - takie wiersze nie przenoszą kosztu nabycia.',
                $skippedZero,
            ));
        }

        if ($proposedFromExchange) {
            $messages[] = ImportMessage::warning(
                $source->name,
                'Kraj uzyskania dochodu został ustalony z giełdy podanej w pliku (kolumna '
                .'"Giełda referencyjna", a gdy jej nie ma - "Miejsce wykonania"). To kraj notowania '
                .'papieru, a nie zawsze kraj źródła dochodu - sprawdź kolumnę "Kraj" przed obliczeniem.',
            );
        }

        // Reported from here rather than from matchTrades(): that method only
        // walks closed positions, so a lot that has not been sold yet would
        // reach the workbench with a blank country and no explanation.
        if ([] !== $unresolvedVenues) {
            $messages[] = ImportMessage::warning($source->name, sprintf(
                'Nie rozpoznano kodu giełdy: %s. Dla tych pozycji kraj pozostał pusty - uzupełnij '
                .'kolumnę "Kraj" ręcznie przed obliczeniem.',
                implode(', ', array_keys($unresolvedVenues)),
            ));
        }

        if ($rowsWithoutVenue > 0) {
            $messages[] = ImportMessage::warning($source->name, sprintf(
                'Plik nie podaje giełdy dla %d wiersz(y), więc kraju nie da się zaproponować. '
                .'Uzupełnij kolumnę "Kraj" ręcznie przed obliczeniem.',
                $rowsWithoutVenue,
            ));
        }

        if (!$header->has(DegiroHeader::AUTOFX)) {
            $messages[] = ImportMessage::info($source->name, sprintf(
                'Kwoty pochodzą z kolumny "%s" i zawierają opłaty transakcyjne w takiej postaci, w jakiej '
                .'wyeksportował je DEGIRO. Plik nie ma kolumny AutoFX - starsze eksporty DEGIRO nie ujmowały '
                .'prowizji za przewalutowanie (AutoFX) w kwocie całkowitej. Kalkulator nie dolicza opłat, '
                .'których nie ma w pliku; jeśli płaciłeś AutoFX, sprawdź kwoty w zestawieniu konta.',
                $header->columns[$total[0]],
            ));
        }

        return new TradeExtraction($trades, $messages);
    }

    public function matchTrades(array $trades): ImportResult
    {
        if ([] === $trades) {
            return new ImportResult();
        }

        $orderingMessages = self::ambiguousOrderingErrors($trades);
        if ([] !== $orderingMessages) {
            return new ImportResult([], [], $orderingMessages);
        }

        $fifo = $this->fifoMatcher->match($trades);

        [$countryByIsin, $venueMessages] = self::venueCountries($trades);

        $positions = [];
        $messages = $venueMessages;
        $unknownCountry = false;

        foreach ($fifo->matches as $match) {
            $isin = $match->symbol;
            $instrument = $match->instrument();
            // The country comes from the per-ISIN consensus, never from this
            // match's own instrument: FifoMatch::instrument() prefers the sell
            // leg, so a buy on one exchange closed by a sell on another would
            // otherwise settle under whichever leg happened to be later.
            $country = $countryByIsin[$isin] ?? '';
            $name = null === $instrument || '' === $instrument->displayName ? $isin : $instrument->displayName;

            $buyCurrency = $match->buyCost->currency();
            $sellCurrency = $match->sellProceeds->currency();

            if ($buyCurrency !== $sellCurrency) {
                // The queue is keyed on the ISIN alone, which is right: the same
                // paper bought in one currency and sold in another is one
                // holding, and splitting the queue per currency would leave the
                // sale uncovered and drop the gain from the return. A closed
                // position can only carry one currency, though, so this is
                // refused with an explanation rather than settled in whichever
                // currency happened to come first.
                $messages[] = ImportMessage::error('Import', sprintf(
                    'Pozycja %s została kupiona w %s (%s), a sprzedana w %s (%s). Kalkulator nie rozlicza '
                    .'pozycji zamkniętych w innej walucie niż otwarte - rozlicz ją ręcznie, przeliczając '
                    .'koszt i przychód na PLN po kursach z odpowiednich dni.',
                    $isin,
                    $buyCurrency,
                    $match->buyDate->format('Y-m-d'),
                    $sellCurrency,
                    $match->sellDate->format('Y-m-d'),
                ));

                continue;
            }

            try {
                $positions[] = new ClosedPosition(
                    $name,
                    $country,
                    $buyCurrency,
                    // The execution time ordered the queue; the settled record
                    // is per day, because that is what the NBP rate and the tax
                    // year are per.
                    $match->buyDate->setTime(0, 0),
                    $match->buyCost,
                    $match->sellDate->setTime(0, 0),
                    $match->sellProceeds,
                    $match->quantity,
                    PositionSource::describe($match->buySource, $match->sellSource, CsvFormat::DegiroTransactions),
                    $match->lineageKey(),
                    $match->buyCommission,
                    $match->sellCommission,
                    $match->buyAutoFx,
                    $match->sellAutoFx,
                    $match->broker,
                    $isin,
                    $match->buyTradeId,
                    $match->sellTradeId,
                    $match->buyUnitPrice,
                    $match->sellUnitPrice,
                );
            } catch (InvalidRecordException $e) {
                $messages[] = ImportMessage::error('Import', sprintf(
                    'Pozycja %s (zakup %s, sprzedaż %s): %s',
                    $isin,
                    $match->buyDate->format('Y-m-d'),
                    $match->sellDate->format('Y-m-d'),
                    $e->getMessage(),
                ));

                continue;
            }

            if ('' === $country) {
                $unknownCountry = true;
            }
        }

        foreach ($fifo->unmatchedSells as $unmatched) {
            // No buy leg means no cost basis: the sale is left out of the
            // result and reported - never silently - while the rest of the
            // statement stays usable and the user adds the earlier one.
            $messages[] = ImportMessage::review('Import', $unmatched->describe())
                ->forTab('transactions')
                ->withCode(UnmatchedSell::CODE);
        }

        if ($unknownCountry) {
            $messages[] = ImportMessage::warning(
                'Import',
                'Dla części pozycji nie dało się ustalić kraju z giełdy podanej w pliku. '
                .'Uzupełnij kolumnę "Kraj" ręcznie przed obliczeniem.',
            );
        }

        return new ImportResult($positions, [], $messages);
    }

    /**
     * The listing country proposed for each instrument, agreed across every
     * trade of that instrument in the whole batch.
     *
     * Resolved per ISIN rather than per row on purpose. A closed position holds
     * one country, and {@see \App\Fifo\FifoMatch::instrument()} returns the
     * sell leg first - so a per-row country would let the later leg decide in
     * silence. When two legs of one paper name different countries the proposal
     * is withdrawn: a blank country fails closed in
     * {@see \App\Report\TaxReportBuilder}, while a guess would reach PIT/ZG.
     *
     * @param list<Trade> $trades
     *
     * @return array{array<string, string>, list<ImportMessage>}
     */
    private static function venueCountries(array $trades): array
    {
        /** @var array<string, array<string, array<string, true>>> $seen ISIN => country => venue codes */
        $seen = [];
        foreach ($trades as $trade) {
            $instrument = $trade->instrument;
            if (null === $instrument || '' === $instrument->countryCode) {
                continue;
            }

            $seen[$trade->symbol][$instrument->countryCode][$instrument->exchangeCode] = true;
        }

        $countries = [];
        $messages = [];
        foreach ($seen as $isin => $byCountry) {
            if (1 === count($byCountry)) {
                $countries[$isin] = (string) array_key_first($byCountry);

                continue;
            }

            $codes = [];
            foreach ($byCountry as $country => $venueCodes) {
                foreach (array_keys($venueCodes) as $code) {
                    $codes[] = sprintf('%s -> %s', $code, $country);
                }
            }

            $messages[] = ImportMessage::warning('Import', sprintf(
                'Instrument %s ma w pliku różne giełdy notowania (%s), więc kraju nie da się ustalić '
                .'automatycznie. Uzupełnij kolumnę "Kraj" ręcznie.',
                $isin,
                implode(', ', $codes),
            ));
        }

        // The listing country disagreeing with the ISIN registration country is
        // deliberately *not* reported. Both are accepted readings of where the
        // income from a disposal arose, so it is a setting the user picks
        // ({@see \App\Web\CountrySource}), and repeating it as a finding would
        // put a permanent item in the attention panel that nothing can clear.

        return [$countries, $messages];
    }

    /**
     * Refuses timestamps whose missing sub-minute ordering can change FIFO.
     *
     * DEGIRO reports time only to the minute. Upload order must therefore never
     * decide which of two different-cost buy lots is consumed first, nor whether
     * a same-minute sell happened before or after a buy. Identical-cost buys are
     * harmless: swapping them cannot change any tax figure.
     *
     * @param list<Trade> $trades
     *
     * @return list<ImportMessage>
     */
    private static function ambiguousOrderingErrors(array $trades): array
    {
        /** @var array<string, list<Trade>> $groups */
        $groups = [];
        foreach ($trades as $trade) {
            $groups[$trade->symbol.'|'.$trade->date->format('Y-m-d H:i')][] = $trade;
        }

        $messages = [];
        foreach ($groups as $key => $atMinute) {
            $buys = array_values(array_filter($atMinute, static fn (Trade $trade): bool => $trade->isBuy()));
            $sells = array_values(array_filter($atMinute, static fn (Trade $trade): bool => $trade->isSell()));

            if ([] !== $buys && [] !== $sells) {
                [$symbol, $instant] = explode('|', $key, 2);
                $messages[] = ImportMessage::error('Import', sprintf(
                    'Zakup i sprzedaż %s przypadają na tę samą minutę (%s). DEGIRO nie podaje kolejności '
                    .'wewnątrz minuty, więc nie da się bezpiecznie ustalić FIFO; sprawdź i rozlicz te operacje ręcznie.',
                    $symbol,
                    $instant,
                ));
                continue;
            }

            if (count($buys) < 2) {
                continue;
            }

            $first = $buys[0];
            foreach (array_slice($buys, 1) as $other) {
                $sameCurrency = $first->grossAmount->currency() === $other->grossAmount->currency();
                $left = $first->grossAmount->value()->multipliedBy($other->quantity->abs());
                $right = $other->grossAmount->value()->multipliedBy($first->quantity->abs());

                if ($sameCurrency && 0 === $left->compareTo($right)) {
                    continue;
                }

                [$symbol, $instant] = explode('|', $key, 2);
                $messages[] = ImportMessage::error('Import', sprintf(
                    'Kilka zakupów %s z różnym kosztem jednostkowym przypada na tę samą minutę (%s). '
                    .'Kolejność plików zmieniałaby koszt FIFO, więc import przerwano; ustal kolejność i rozlicz ręcznie.',
                    $symbol,
                    $instant,
                ));
                break;
            }
        }

        return $messages;
    }

    /**
     * Stand-in identifier for a row DEGIRO exported without an Order ID.
     *
     * Derived from the row's own content, never from the file name, so the same
     * export deduplicates however the browser named it - and so a row that
     * appears once in every upload keeps one identity. Combined with
     * {@see Trade::$fillOrdinal} it also gives the FIFO match a lineage key,
     * without which two identical closed positions would collapse into one and
     * halve the gain.
     */
    private static function syntheticId(string $signature): string
    {
        return 'auto:'.substr(hash('sha256', $signature), 0, 24);
    }

    /**
     * @throws InvalidRecordException when the cash flow contradicts the quantity
     */
    private static function assertSignsAgree(Decimal $quantity, Decimal $amount, string $currency): void
    {
        $buy = $quantity->isPositive();

        if ($buy === $amount->isNegative()) {
            return;
        }

        throw new InvalidRecordException(sprintf(
            'Niezgodny znak kwoty: %s %s szt. przy kwocie %s %s. Zakup powinien pomniejszać saldo, '
            .'a sprzedaż je zwiększać - sprawdź, czy plik nie został zmodyfikowany.',
            $buy ? 'zakup' : 'sprzedaż',
            (string) $quantity,
            (string) $amount,
            $currency,
        ));
    }

    /**
     * Currency of the settled total: named in the header for the `Total EUR`
     * style layouts, otherwise in the unnamed column right after the amount.
     *
     * @param list<string>            $row
     * @param array{int, string|null} $total
     *
     * @throws InvalidCurrencyException when neither is a usable code
     */
    private static function currency(DegiroTable $table, array $row, array $total): string
    {
        $currency = $total[1] ?? mb_strtoupper($table->value($row, $total[0] + 1));

        // Validated here so a shifted column shows up as a row error rather
        // than as an amount in the wrong currency.
        Amount::zero($currency);

        return $currency;
    }

    /**
     * @param list<string> $row
     */
    private static function orderId(DegiroTable $table, array $row, ?int $index): ?string
    {
        $id = $table->value($row, $index);

        if ('' === $id && null !== $index) {
            $neighbor = $index + 1;
            if ($neighbor === $table->header->count() - 1 && '' === $table->header->columns[$neighbor]) {
                $id = $table->value($row, $neighbor);
            }
        }

        return '' === $id ? null : $id;
    }

    /**
     * Which venue code answers for a row, and the country it names.
     *
     * The reference exchange is consulted first: it is the *listing* venue, and
     * the listing country is what the user asked the proposal to follow. The
     * execution venue MIC is only a fallback, because a multi-venue order can
     * fill on a pan-European MTF that names no market at all.
     *
     * A blank cell or an unresolvable code does not stop the walk - otherwise a
     * fill on `CEUX` would throw away the `XAMS` sitting in the next column.
     * When nothing resolves, the first code that was actually printed is
     * returned with a blank country so it can be reported verbatim.
     *
     * @return array{string, string} venue code and the country it names
     */
    private static function resolveVenue(string $reference, string $venue): array
    {
        foreach ([$reference, $venue] as $code) {
            if ('' !== $code && '' !== ExchangeCountry::country($code)) {
                return [$code, ExchangeCountry::country($code)];
            }
        }

        foreach ([$reference, $venue] as $code) {
            if ('' !== $code) {
                return [$code, ''];
            }
        }

        return ['', ''];
    }

    /**
     * Removes DEGIRO's zero-sum rename correction, never an ordinary trade.
     * Every stated condition is required; a plausible but incomplete pair is
     * an error because leaving just one side in FIFO would create or consume a
     * lot that never economically existed.
     *
     * @param list<Trade> $trades
     *
     * @return array{list<Trade>, int, list<ImportMessage>}
     */
    private static function removeNeutralProductChanges(array $trades, string $source): array
    {
        /** @var array<string, list<int>> $groups */
        $groups = [];
        foreach ($trades as $index => $trade) {
            if ('00:00:00' !== $trade->date->format('H:i:s')
                || $trade->externalIdReported
                || '' !== trim($trade->executionVenue)
                || null === $trade->unitPrice) {
                continue;
            }

            $groups[implode('|', [
                $trade->symbol,
                $trade->date->format('Y-m-d H:i:s'),
                $trade->grossAmount->currency(),
                $trade->unitPrice->currency(),
                (string) $trade->unitPrice->value(),
            ])][] = $index;
        }

        $drop = [];
        $incomplete = [];

        foreach ($groups as $indexes) {
            foreach ($indexes as $left) {
                if (isset($drop[$left])) {
                    continue;
                }

                $leftTrade = $trades[$left];
                foreach ($indexes as $right) {
                    if ($right <= $left || isset($drop[$right])) {
                        continue;
                    }

                    $rightTrade = $trades[$right];
                    if ($leftTrade->isBuy() === $rightTrade->isBuy()
                        || self::productName($leftTrade) === self::productName($rightTrade)) {
                        continue;
                    }

                    $incomplete[$left] = true;
                    $incomplete[$right] = true;

                    if (0 !== $leftTrade->quantity->abs()->compareTo($rightTrade->quantity->abs())
                        || 0 !== $leftTrade->grossAmount->compareTo($rightTrade->grossAmount)) {
                        continue;
                    }

                    $drop[$left] = true;
                    $drop[$right] = true;
                    unset($incomplete[$left], $incomplete[$right]);
                    break;
                }
            }
        }

        $messages = [];
        if ([] !== $incomplete) {
            $messages[] = ImportMessage::error(
                $source,
                'Wykryto niepełną korektę zmiany produktu o północy: przeciwne wpisy mają różne ilości lub kwoty. '
                .'Nie można bezpiecznie usunąć tylko jednej strony; zweryfikuj te operacje ręcznie.',
            );
        }

        $kept = [];
        foreach ($trades as $index => $trade) {
            if (!isset($drop[$index])) {
                $kept[] = $trade;
            }
        }

        return [$kept, intdiv(count($drop), 2), $messages];
    }

    private static function productName(Trade $trade): string
    {
        return DegiroHeader::normalize($trade->instrument->displayName ?? '');
    }

    /**
     * Read an audit fee only from its explicit column. Blank cells remain null,
     * while an explicit zero remains an Amount(0). Its adjacent/header currency
     * must agree with Total; guessing or deriving the fee is forbidden.
     *
     * @param list<string> $row
     * @param array{int, string|null}|null $column
     */
    private static function optionalFee(
        DegiroTable $table,
        array $row,
        ?array $column,
        string $totalCurrency,
        string $label,
    ): ?Amount {
        if (null === $column) {
            return null;
        }

        [$amountIndex, $headerCurrency] = $column;
        $raw = $table->value($row, $amountIndex);
        if ('' === $raw) {
            return null;
        }

        $adjacent = mb_strtoupper($table->value($row, $amountIndex + 1));
        $currency = $headerCurrency
            ?? (1 === preg_match('/^[A-Z]{3}$/', $adjacent) ? $adjacent : $totalCurrency);
        if ($currency !== $totalCurrency) {
            throw new InvalidRecordException(sprintf(
                'Waluta pola %s (%s) nie zgadza się z walutą Total (%s).',
                $label,
                '' === $currency ? 'brak' : $currency,
                $totalCurrency,
            ));
        }

        return Amount::fromDecimal(
            NumberParser::parseLocalizedOrZero($raw, $table->decimalComma())->abs(),
            $currency,
        );
    }

    /**
     * Price and its currency form one optional audit value. Unlike fees, the
     * currency is never inferred from Total: DEGIRO can execute a trade in a
     * different currency than the settled cash amount.
     *
     * @param list<string> $row
     * @param array{int, string|null} $column
     */
    private static function optionalPrice(DegiroTable $table, array $row, array $column): ?Amount
    {
        [$priceIndex, $headerCurrency] = $column;
        $raw = $table->value($row, $priceIndex);
        $adjacent = mb_strtoupper($table->value($row, $priceIndex + 1));
        $currency = $headerCurrency ?? $adjacent;

        // The adjacent currency cell can be populated by DEGIRO even when the
        // optional price itself is blank. In that case there is no partial
        // audit value to reject: the price is simply unavailable.
        if ('' === $raw) {
            return null;
        }
        if ('' === $currency) {
            throw new InvalidRecordException('Cena wykonania i waluta ceny muszą być podane razem.');
        }

        $price = Amount::fromDecimal(
            NumberParser::parseLocalizedOrZero($raw, $table->decimalComma()),
            $currency,
        );
        if (!$price->isPositive()) {
            throw InvalidRecordException::amountMustBePositive('cena wykonania', $price);
        }

        return $price;
    }
}

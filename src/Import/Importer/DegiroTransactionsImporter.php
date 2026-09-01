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
use App\Import\CsvFormat;
use App\Import\CsvSource;
use App\Import\Degiro\DegiroCsvReader;
use App\Import\Degiro\DegiroHeader;
use App\Import\Degiro\DegiroTable;
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
 * carries the transaction fee - which Polish rules count towards the cost basis
 * and against the proceeds. Two consequences worth knowing:
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
            'kurs' => $header->indexOf(DegiroHeader::PRICE),
        ];

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

        $trades = [];
        $skippedZero = 0;

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

                $quantity = NumberParser::parseOrZero($table->value($row, $columns['liczba']), $decimalComma);
                if ($quantity->isZero()) {
                    ++$skippedZero;

                    continue;
                }

                $price = NumberParser::parseOrZero($table->value($row, $columns['kurs']), $decimalComma);
                $currency = self::currency($table, $row, $total);
                $amount = NumberParser::parseOrZero($table->value($row, $total[0]), $decimalComma);

                if ($price->isZero() || $amount->isZero()) {
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
                    $orderId ?? '',
                ]);
                $ordinal = $ordinals[$signature] = ($ordinals[$signature] ?? 0) + 1;

                $trades[] = new Trade(
                    $isin,
                    $date,
                    $quantity,
                    Amount::fromDecimal($amount->abs(), $currency),
                    $orderId ?? self::syntheticId($signature),
                    $source->name,
                    new InstrumentDetails(
                        $table->value($row, $productIndex) ?: $isin,
                        Isin::country($isin),
                    ),
                    $ordinal,
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, $e->getMessage(), $line);
            }
        }

        if ($skippedZero > 0) {
            $messages[] = ImportMessage::info($source->name, sprintf(
                'Pominięto %d wiersz(y) z zerową liczbą sztuk - takie wiersze nie przenoszą kosztu nabycia.',
                $skippedZero,
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

        $positions = [];
        $messages = [];
        $inferred = false;
        $unknownCountry = false;

        foreach ($fifo->matches as $match) {
            $isin = $match->symbol;
            $instrument = $match->instrument();
            $country = null === $instrument ? '' : $instrument->countryCode;
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

            '' === $country ? $unknownCountry = true : $inferred = true;
        }

        foreach ($fifo->unmatchedSells as $unmatched) {
            // Fatal, not a warning: without the buy leg the cost basis is
            // missing, so that sale's whole proceeds would look like gain. A
            // return that silently omits one position while settling the rest
            // is worse than no result at all.
            $messages[] = ImportMessage::error('Import', sprintf(
                'Sprzedaż %s z dnia %s (%s szt.) nie ma pokrycia w zakupach z wgranych plików, '
                .'więc nie da się ustalić kosztu nabycia. Dograj wcześniejsze zestawienie transakcji '
                .'DEGIRO albo uzupełnij tę pozycję ręcznie w formacie własnym.',
                $unmatched->symbol,
                $unmatched->date->format('Y-m-d'),
                (string) $unmatched->quantity,
            ));
        }

        if ($inferred) {
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
                'Dla części pozycji nie dało się ustalić kraju z numeru ISIN (prefiks nie jest kodem kraju). '
                .'Uzupełnij kolumnę "Kraj" ręcznie przed obliczeniem.',
            );
        }

        return new ImportResult($positions, [], $messages);
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

        return '' === $id ? null : $id;
    }

}

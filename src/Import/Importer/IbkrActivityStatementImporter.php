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
use App\Import\Degiro\ExchangeCountry;
use App\Import\Degiro\Isin;
use App\Import\Ibkr\ActivityStatement;
use App\Import\Ibkr\ActivityStatementReader;
use App\Import\Ibkr\ActivityStatementReadException;
use App\Import\Ibkr\ActivityStatementRow;
use App\Import\Ibkr\IbkrExchange;
use App\Import\ImportMessage;
use App\Import\ImportResult;
use App\Import\Parser\DateParser;
use App\Import\Parser\NumberParser;
use App\Import\TradeIdScope;
use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;

/**
 * Reads the Interactive Brokers Activity Statement (Reports → Statements →
 * Activity, CSV) - the statement every account can download without setting up
 * a Flex Query.
 *
 * Trades come from the `Trades` section, one row per order:
 *
 *   Trades,Data,Order,Stocks,USD,AAA,"2026-06-22, 09:55:39",3,238.77,...,-716.31,-0.35,...,O
 *
 * Amounts are in the trade currency. `Proceeds` is quantity × price with the
 * sign of the cash flow, `Comm/Fee` the commission (negative when charged), so
 * the settled cash is their sum - exactly what DEGIRO calls Total - and the sell
 * commission is known separately, which lets PIT-38 split przychód and koszty.
 *
 * The ISIN and the listing exchange are not in the trade rows but in Financial
 * Instrument Information at the end of the file. The FIFO queue is keyed on
 * `ISIN@CURRENCY`: the ISIN survives a ticker change between yearly statements,
 * and the currency keeps a dual-listed paper's two lines apart as the Flex
 * export does. The exchange becomes a MIC so the country setting can re-derive
 * the proposal later.
 *
 * Dividends, payments in lieu and withholding come from their own sections and
 * are assembled across every uploaded statement: IBKR posts a withholding
 * correction whenever it happens, which is often another statement's period.
 *
 * Nothing from Account Information - name, account number - is ever read.
 */
final class IbkrActivityStatementImporter implements TradeSourceImporterInterface, BatchImporterInterface
{
    private const string TRADES = 'Trades';

    private const string INSTRUMENTS = 'Financial Instrument Information';

    private const string CORPORATE_ACTIONS = 'Corporate Actions';

    private const string DIVIDENDS = 'Dividends';

    private const string PAYMENT_IN_LIEU = 'Payment In Lieu Of Dividends';

    private const string WITHHOLDING = 'Withholding Tax';

    private const string STOCKS = 'Stocks';

    private const string FOREX = 'Forex';

    private const string BROKER = 'IBKR';

    /** Codes that undo or rewrite an earlier execution; neither can be settled from one row. */
    private const array REWRITE_CODES = ['Ca', 'Co'];

    /** Codes marking shares delivered by an option assignment or exercise. */
    private const array DELIVERY_CODES = ['A', 'Ex', 'AEx', 'MEx', 'GEA'];

    /** `SYMBOL(ISIN) ...` at the start of every dividend and withholding description. */
    private const string PAYMENT_DESCRIPTION = '/^(?<symbol>[^(]+)\((?<isin>[A-Z]{2}[A-Z0-9]{9}\d)\)/';

    public function __construct(
        private readonly FifoMatcher $fifoMatcher,
        private readonly int $maxRowsPerFile = AbstractCsvImporter::DEFAULT_MAX_ROWS_PER_FILE,
    ) {
    }

    public function supports(CsvFormat $format): bool
    {
        return CsvFormat::IbkrActivityStatement === $format;
    }

    public function tradeIdScope(): TradeIdScope
    {
        // The statement reports no execution ID; each trade gets one derived
        // from its own content and its occurrence in the file, so it names
        // exactly one row and overlapping statements deduplicate on it.
        return TradeIdScope::Fill;
    }

    public function tradeIdLabel(): string
    {
        return 'identyfikator wiersza Activity Statement';
    }

    public function import(CsvSource $source): ImportResult
    {
        $extraction = $this->extractTrades($source);

        return $this->matchTrades($extraction->trades)
            ->withMessages($extraction->messages)
            ->merge($this->importMany([$source]));
    }

    public function extractTrades(CsvSource $source): TradeExtraction
    {
        try {
            $statement = ActivityStatementReader::read(
                $source,
                [self::TRADES, self::INSTRUMENTS, self::CORPORATE_ACTIONS],
                $this->maxRowsPerFile,
            );
        } catch (ActivityStatementReadException $e) {
            return new TradeExtraction([], [ImportMessage::error($source->name, $e->getMessage())]);
        }

        $messages = self::corporateActionErrors($source, $statement);
        $instruments = self::instruments($statement);
        $discriminator = self::orderDiscriminator($statement);

        $trades = [];
        /** @var array<string, int> $skippedClasses */
        $skippedClasses = [];
        $forexRows = 0;
        $zeroRows = 0;
        /** @var list<string> $deliveries */
        $deliveries = [];
        /** @var array<string, true> $unknownExchanges */
        $unknownExchanges = [];
        /** @var array<string, true> $withoutExchange */
        $withoutExchange = [];
        /** @var array<string, true> $withoutIsin */
        $withoutIsin = [];
        $proposed = false;

        /**
         * Occurrences of each identical row seen so far in *this* file.
         *
         * @var array<string, int> $ordinals
         */
        $ordinals = [];

        foreach ($statement->rows(self::TRADES) as $row) {
            $kind = $row->get('datadiscriminator');
            if ($kind !== $discriminator) {
                // Closed-lot detail rows break an order down by the lot IBKR's
                // own method closed; they are not trades of their own.
                if (!in_array($kind, ['Order', 'Trade', 'ClosedLot'], true)) {
                    $messages[] = ImportMessage::error($source->name, sprintf(
                        'Nieznany rodzaj wiersza transakcji "%s".',
                        $kind,
                    ), $row->line);
                }

                continue;
            }

            $category = $row->get('asset category');
            if (self::FOREX === $category) {
                ++$forexRows;

                continue;
            }

            if (self::STOCKS !== $category) {
                $skippedClasses[$category] = ($skippedClasses[$category] ?? 0) + 1;

                continue;
            }

            try {
                $symbol = $row->get('symbol');
                if ('' === $symbol) {
                    throw new InvalidRecordException('Brak symbolu instrumentu.');
                }

                $currency = strtoupper($row->get('currency'));
                $date = self::dateTime($row->get('date/time'));
                $codes = self::codes($row->get('code'));

                $rewrite = array_intersect(self::REWRITE_CODES, $codes);
                if ([] !== $rewrite) {
                    throw new InvalidRecordException(sprintf(
                        'Transakcja %s z dnia %s jest oznaczona kodem %s (anulowana lub korygowana). '
                        .'Kalkulator nie odtwarza korekt IBKR - rozlicz ten instrument ręcznie.',
                        $symbol,
                        $date->format('Y-m-d'),
                        implode(';', $rewrite),
                    ));
                }

                $quantity = NumberParser::parse($row->get('quantity'));
                if ($quantity->isZero()) {
                    ++$zeroRows;

                    continue;
                }

                $proceeds = NumberParser::parse($row->get('proceeds'));
                if ($proceeds->isZero()) {
                    // Shares that move without cash are a corporate action - a
                    // split, a merger, a spin-off. Settling around one would
                    // change the cost of every later sale of the paper.
                    throw new InvalidRecordException(sprintf(
                        'Wiersz %s (%s szt.) nie ma kwoty - wygląda na operację korporacyjną '
                        .'(split, scalenie, przydział). Kalkulator nie rozlicza takich zdarzeń, a pominięcie '
                        .'tego wiersza zmieniłoby koszt kolejnych sprzedaży tego papieru. Rozlicz ten instrument ręcznie.',
                        $symbol,
                        (string) $quantity,
                    ));
                }

                $fee = NumberParser::parseOrZero($row->get('comm/fee'));
                $cash = $proceeds->plus($fee);
                self::assertCashFollowsTheSide($symbol, $quantity, $proceeds, $cash);

                $instrument = $instruments[$symbol] ?? null;
                $isin = $instrument['isin'] ?? '';
                if ('' === $isin) {
                    $withoutIsin[$symbol] = true;
                }

                [$exchangeCode, $country] = self::venue($instrument['exchange'] ?? '');
                if ('' === $exchangeCode) {
                    $withoutExchange[$symbol] = true;
                } elseif ('' === $country) {
                    $unknownExchanges[$exchangeCode] = true;
                } else {
                    $proposed = true;
                }

                if ([] !== array_intersect(self::DELIVERY_CODES, $codes)) {
                    $deliveries[] = sprintf('%s %s (%s szt.)', $symbol, $date->format('Y-m-d'), (string) $quantity);
                }

                $pool = ('' === $isin ? $symbol : $isin).'@'.$currency;
                $price = self::unitPrice($row, $currency);

                // Everything that makes this row the row it is. Two rows sharing
                // it are indistinguishable, so their order inside the file is
                // the only thing that separates them.
                $signature = implode('|', [
                    $pool,
                    $symbol,
                    $currency,
                    $date->format('Y-m-d H:i:s'),
                    (string) $quantity,
                    (string) $proceeds,
                    (string) $fee,
                    null === $price ? '' : (string) $price->value(),
                ]);
                $ordinal = $ordinals[$signature] = ($ordinals[$signature] ?? 0) + 1;

                $trades[] = new Trade(
                    $symbol,
                    $date,
                    $quantity,
                    Amount::fromDecimal(self::atLeastCents($cash->abs()), $currency),
                    externalId: 'auto:'.substr(hash('sha256', $signature.'|'.$ordinal), 0, 24),
                    source: sprintf('%s (%s)', $source->name, CsvFormat::IbkrActivityStatement->label()),
                    instrument: new InstrumentDetails($instrument['name'] ?? $symbol, $country, $exchangeCode),
                    fillOrdinal: $ordinal,
                    externalIdReported: false,
                    unitPrice: $price,
                    broker: self::BROKER,
                    // A rebate (positive Comm/Fee) is not a fee: the cash already
                    // carries it, and the sell side then keeps przychód = cash.
                    commission: $fee->isPositive() ? null : Amount::fromDecimal(self::atLeastCents($fee->abs()), $currency),
                    fifoPool: $pool,
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, $e->getMessage(), $row->line);
            }
        }

        foreach ($skippedClasses as $category => $count) {
            $messages[] = ImportMessage::review($source->name, sprintf(
                'Pominięto %d transakcj(ę/e/i) klasy "%s" - kalkulator rozlicza wyłącznie akcje i ETF-y, '
                .'więc pola 22/23 PIT-38 ich nie obejmują - dolicz je samodzielnie.',
                $count,
                $category,
            ))->forTab('transactions');
        }

        if ($forexRows > 0) {
            $messages[] = ImportMessage::info($source->name, sprintf(
                'Pominięto %d wymian(ę/y) walut (Forex) - to nie są transakcje papierami wartościowymi.',
                $forexRows,
            ));
        }

        if ($zeroRows > 0) {
            $messages[] = ImportMessage::info($source->name, sprintf(
                'Pominięto %d wiersz(y) z zerową liczbą sztuk - takie wiersze nie przenoszą kosztu nabycia.',
                $zeroRows,
            ));
        }

        if ([] !== $deliveries) {
            $messages[] = ImportMessage::review($source->name, sprintf(
                'Akcje z przydziału lub wykonania opcji: %s. Weszły do FIFO po cenie wykonania - premia z opcji '
                .'nie zmienia ich kosztu nabycia i jest rozliczana osobno.',
                implode('; ', $deliveries),
            ))->forTab('transactions');
        }

        if ($proposed) {
            $messages[] = ImportMessage::warning(
                $source->name,
                'Kraj uzyskania dochodu został ustalony z giełdy notowania podanej w sekcji Financial Instrument '
                .'Information. To kraj notowania papieru, a nie zawsze kraj źródła dochodu - sprawdź kolumnę '
                .'"Kraj" przed obliczeniem.',
            );
        }

        // Reported here rather than from matchTrades(): that method only walks
        // closed positions, so an open lot would get no explanation.
        if ([] !== $unknownExchanges) {
            $messages[] = ImportMessage::warning($source->name, sprintf(
                'Nie rozpoznano kodu giełdy: %s. Dla tych pozycji kraj pozostał pusty - uzupełnij '
                .'kolumnę "Kraj" ręcznie przed obliczeniem.',
                implode(', ', array_keys($unknownExchanges)),
            ));
        }

        if ([] !== $withoutExchange) {
            $messages[] = ImportMessage::warning($source->name, sprintf(
                'Wyciąg nie podaje giełdy dla: %s, więc kraju nie da się zaproponować. Uzupełnij kolumnę '
                .'"Kraj" ręcznie przed obliczeniem.',
                implode(', ', array_keys($withoutExchange)),
            ));
        }

        if ([] !== $withoutIsin) {
            $messages[] = ImportMessage::warning($source->name, sprintf(
                'Wyciąg nie podaje numeru ISIN dla: %s. Te pozycje są parowane po symbolu, więc zmiana '
                .'symbolu między rocznymi wyciągami rozdzieli kolejkę FIFO.',
                implode(', ', array_keys($withoutIsin)),
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
        [$countryByPool, $messages] = self::poolCountries($trades);

        /** @var array<string, string> $poolById */
        $poolById = [];
        foreach ($trades as $trade) {
            $poolById[$trade->id()] = $trade->fifoPool;
        }

        $positions = [];
        $unknownCountry = false;

        foreach ($fifo->matches as $match) {
            $instrument = $match->instrument();
            $pool = $poolById[$match->sellTradeId] ?? $poolById[$match->buyTradeId] ?? '';
            // The country comes from the per-pool consensus, never from this
            // match's own leg: FifoMatch::instrument() prefers the sell leg, so
            // a buy and a sell on different exchanges would otherwise settle
            // under whichever leg happened to be later.
            $country = $countryByPool[$pool] ?? '';
            $name = null === $instrument || '' === $instrument->displayName ? $match->symbol : $instrument->displayName;

            try {
                $positions[] = new ClosedPosition(
                    $name,
                    $country,
                    $match->buyCost->currency(),
                    // The execution time ordered the queue; the settled record
                    // is per day, because the NBP rate and the tax year are.
                    $match->buyDate->setTime(0, 0),
                    $match->buyCost,
                    $match->sellDate->setTime(0, 0),
                    $match->sellProceeds,
                    $match->quantity,
                    PositionSource::describe($match->buySource, $match->sellSource, CsvFormat::IbkrActivityStatement),
                    $match->lineageKey(),
                    $match->buyCommission,
                    $match->sellCommission,
                    $match->buyAutoFx,
                    $match->sellAutoFx,
                    $match->broker,
                    $match->symbol,
                    $match->buyTradeId,
                    $match->sellTradeId,
                    $match->buyUnitPrice,
                    $match->sellUnitPrice,
                );
            } catch (InvalidRecordException $e) {
                $messages[] = ImportMessage::error('Import', sprintf(
                    'Pozycja %s (zakup %s, sprzedaż %s): %s',
                    $match->symbol,
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
            // Fatal, not a warning: without its buy leg a sale has no cost
            // basis, so its whole proceeds would read as gain.
            $messages[] = ImportMessage::error('Import', sprintf(
                'Sprzedaż %s z dnia %s (%s szt.) nie ma pokrycia w zakupach z wgranych plików, '
                .'więc nie da się ustalić kosztu nabycia. Dograj Activity Statement za wcześniejszy rok, '
                .'w którym kupowałeś ten papier.',
                $unmatched->symbol,
                $unmatched->date->format('Y-m-d'),
                (string) $unmatched->quantity,
            ));
        }

        if ($unknownCountry) {
            $messages[] = ImportMessage::warning(
                'Import',
                'Dla części pozycji nie dało się ustalić kraju z giełdy notowania. '
                .'Uzupełnij kolumnę "Kraj" ręcznie przed obliczeniem.',
            );
        }

        return new ImportResult($positions, [], $messages);
    }

    public function importMany(array $sources): ImportResult
    {
        /** @var array<string, array{string, string, string, DateTimeImmutable, string}> $payments key => [symbol, isin, currency, date, file] */
        $payments = [];
        /** @var array<string, Decimal> $gross */
        $gross = [];
        /** @var array<string, Decimal> $withheld */
        $withheld = [];
        /** @var array<string, array{string, string, DateTimeImmutable, string}> $withholdingOnly key => [symbol, currency, date, file] */
        $withholdingOnly = [];
        $messages = [];

        foreach ($sources as $source) {
            try {
                $statement = ActivityStatementReader::read(
                    $source,
                    [self::DIVIDENDS, self::PAYMENT_IN_LIEU, self::WITHHOLDING],
                    $this->maxRowsPerFile,
                );
            } catch (ActivityStatementReadException) {
                // extractTrades() reads the same file first and reports why it
                // is unusable; saying it twice would only add noise.
                continue;
            }

            foreach ([...$statement->rows(self::DIVIDENDS), ...$statement->rows(self::PAYMENT_IN_LIEU)] as $row) {
                try {
                    $payment = self::payment($row);
                    if (null === $payment) {
                        continue;
                    }

                    [$symbol, $isin, $currency, $date, $amount] = $payment;
                    if ('' === $isin) {
                        throw new InvalidRecordException(sprintf(
                            'Nie da się ustalić instrumentu z opisu wypłaty "%s".',
                            $row->get('description'),
                        ));
                    }

                    $key = implode('|', [$isin, $currency, $date->format('Y-m-d')]);
                    $payments[$key] ??= [$symbol, $isin, $currency, $date, $source->name];
                    $gross[$key] = ($gross[$key] ?? Decimal::zero())->plus($amount);
                } catch (InvalidNumberException|InvalidDateException|InvalidRecordException $e) {
                    $messages[] = ImportMessage::error($source->name, $e->getMessage(), $row->line)->forTab('dividends');
                }
            }

            foreach ($statement->rows(self::WITHHOLDING) as $row) {
                try {
                    $payment = self::payment($row);
                    // Withholding on credit interest names no instrument;
                    // interest is outside what this calculator settles.
                    if (null === $payment || '' === $payment[1]) {
                        continue;
                    }

                    [$symbol, $isin, $currency, $date, $amount] = $payment;
                    $key = implode('|', [$isin, $currency, $date->format('Y-m-d')]);
                    $withholdingOnly[$key] ??= [$symbol, $currency, $date, $source->name];
                    $withheld[$key] = ($withheld[$key] ?? Decimal::zero())->plus($amount);
                } catch (InvalidNumberException|InvalidDateException|InvalidRecordException $e) {
                    $messages[] = ImportMessage::error($source->name, $e->getMessage(), $row->line)->forTab('dividends');
                }
            }
        }

        $dividends = [];
        /** @var list<string> $reversals */
        $reversals = [];
        /** @var list<string> $refunds */
        $refunds = [];
        $inferredCountry = false;
        $unknownCountry = false;

        foreach ($payments as $key => [$symbol, $isin, $currency, $date, $file]) {
            $amount = $gross[$key];
            $tax = $withheld[$key] ?? Decimal::zero();
            unset($withholdingOnly[$key]);

            if (!$amount->isPositive()) {
                // A reversal posted on its own: the cash moved now, but it is
                // not income now - it corrects the year the payment was settled
                // in. `Dividend` refuses a non-positive gross anyway.
                $reversals[] = sprintf('%s (%s %s, %s)', $symbol, (string) $amount, $currency, $date->format('Y-m-d'));

                continue;
            }

            if ($tax->isPositive()) {
                // More refunded than withheld on this day: the refund belongs
                // to an earlier payment. The dividend is kept with no credit,
                // which can only overstate the tax, and the user is told.
                $refunds[] = sprintf('%s (%s %s, %s)', $symbol, (string) $tax, $currency, $date->format('Y-m-d'));
                $tax = Decimal::zero();
            }

            $country = Isin::country($isin);

            try {
                $dividends[] = new Dividend(
                    $symbol,
                    $country,
                    $currency,
                    $date,
                    Amount::fromDecimal($amount, $currency),
                    Amount::fromDecimal($tax->abs(), $currency),
                    sprintf('%s (%s)', $file, CsvFormat::IbkrActivityStatement->label()),
                );
            } catch (InvalidRecordException|InvalidCurrencyException $e) {
                $messages[] = ImportMessage::error($file, sprintf(
                    'Dywidenda %s z dnia %s: %s',
                    $symbol,
                    $date->format('Y-m-d'),
                    $e->getMessage(),
                ))->forTab('dividends');

                continue;
            }

            '' === $country ? $unknownCountry = true : $inferredCountry = true;
        }

        foreach ($withholdingOnly as $key => [$symbol, $currency, $date]) {
            if (!$withheld[$key]->isZero()) {
                $refunds[] = sprintf('%s (%s %s, %s)', $symbol, (string) $withheld[$key], $currency, $date->format('Y-m-d'));
            }
        }

        if ([] !== $reversals) {
            $messages[] = ImportMessage::review('Import', sprintf(
                'Pominięto %d storn(o/a) wcześniejszych wypłat: %s. Ujemna wypłata nie jest przychodem '
                .'bieżącego roku - koryguje rok, w którym rozliczono pierwotną wypłatę, więc rozlicz ją tam.',
                count($reversals),
                implode('; ', $reversals),
            ))->forTab('dividends');
        }

        if ([] !== $refunds) {
            $messages[] = ImportMessage::review('Import', sprintf(
                'Korekty podatku u źródła bez wypłaty z tego samego dnia: %s. Nie przypisano ich do żadnej '
                .'dywidendy - jeśli dotyczą wypłaty z innego roku, skoryguj tamten rok.',
                implode('; ', $refunds),
            ))->forTab('dividends');
        }

        if ($inferredCountry) {
            $messages[] = ImportMessage::warning(
                'Import',
                'Kraj dywidendy został ustalony z dwóch pierwszych znaków numeru ISIN. To kraj rejestracji '
                .'papieru, a nie zawsze kraj źródła dochodu - sprawdź kolumnę "Kraj" przed obliczeniem.',
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
     * @return list<ImportMessage>
     */
    private static function corporateActionErrors(CsvSource $source, ActivityStatement $statement): array
    {
        $rows = $statement->rows(self::CORPORATE_ACTIONS);
        if ([] === $rows) {
            return [];
        }

        $described = array_map(static fn (ActivityStatementRow $row): string => $row->get('description'), array_slice($rows, 0, 5));

        return [ImportMessage::error($source->name, sprintf(
            'Wyciąg zawiera operacje korporacyjne (%s%s). Kalkulator ich nie rozlicza, a pominięcie zmieniłoby '
            .'koszt kolejnych sprzedaży tych papierów. Rozlicz te instrumenty ręcznie.',
            implode('; ', $described),
            count($rows) > 5 ? sprintf(' i %d innych', count($rows) - 5) : '',
        ))];
    }

    /**
     * Stock rows of Financial Instrument Information, by symbol.
     *
     * @return array<string, array{name: string, isin: string, exchange: string}>
     */
    private static function instruments(ActivityStatement $statement): array
    {
        $instruments = [];
        foreach ($statement->rows(self::INSTRUMENTS) as $row) {
            if (self::STOCKS !== $row->get('asset category')) {
                continue;
            }

            $isin = mb_strtoupper($row->get('security id'));
            $details = [
                'name' => $row->get('description'),
                'isin' => Isin::isWellFormed($isin) ? $isin : '',
                'exchange' => $row->get('listing exch'),
            ];

            // After a ticker change IBKR lists every symbol the contract has
            // carried in one field ("CNDX, CSNDX"); trades use either.
            foreach (explode(',', $row->get('symbol')) as $symbol) {
                if ('' !== trim($symbol)) {
                    $instruments[trim($symbol)] = $details;
                }
            }
        }

        return $instruments;
    }

    /**
     * Orders, unless the statement was generated with executions only.
     */
    private static function orderDiscriminator(ActivityStatement $statement): string
    {
        foreach ($statement->rows(self::TRADES) as $row) {
            if ('Order' === $row->get('datadiscriminator')) {
                return 'Order';
            }
        }

        return 'Trade';
    }

    /**
     * @return array{string, string} the exchange as a MIC (or IBKR's own code
     *                               when unknown) and the country it proposes
     */
    private static function venue(string $code): array
    {
        $code = trim($code);
        if ('' === $code) {
            return ['', ''];
        }

        $mic = IbkrExchange::mic($code);

        return null === $mic ? [$code, ''] : [$mic, ExchangeCountry::country($mic)];
    }

    /**
     * @return list<string>
     */
    private static function codes(string $raw): array
    {
        return array_values(array_filter(array_map(trim(...), explode(';', $raw)), static fn (string $c): bool => '' !== $c));
    }

    private static function dateTime(string $raw): DateTimeImmutable
    {
        $parts = array_map(trim(...), explode(',', $raw, 2));

        return DateParser::parseWithTime($parts[0], $parts[1] ?? '');
    }

    /**
     * IBKR prints the shortest form of a number, so a round trade arrives as
     * `-1000` with a commission of `0`. FIFO prorates a lot at the amount's own
     * scale, which would round a partial sale of such a lot to whole units of
     * currency; cents are the floor.
     */
    private static function atLeastCents(Decimal $value): Decimal
    {
        return $value->scale() < 2 ? $value->toScale(2) : $value;
    }

    private static function unitPrice(ActivityStatementRow $row, string $currency): ?Amount
    {
        $raw = $row->get('t. price');
        if ('' === $raw) {
            return null;
        }

        $price = NumberParser::parse($raw);

        return $price->isPositive() ? Amount::fromDecimal($price, $currency) : null;
    }

    private static function assertCashFollowsTheSide(string $symbol, Decimal $quantity, Decimal $proceeds, Decimal $cash): void
    {
        $buy = $quantity->isPositive();

        if ($buy !== $proceeds->isNegative()) {
            throw new InvalidRecordException(sprintf(
                'Kwota Proceeds %s dla %s ma znak sprzeczny z liczbą sztuk %s - zakup musi mieć ujemną kwotę, '
                .'sprzedaż dodatnią.',
                (string) $proceeds,
                $symbol,
                (string) $quantity,
            ));
        }

        if ($cash->isZero() || $buy !== $cash->isNegative()) {
            throw new InvalidRecordException(sprintf(
                'Po doliczeniu prowizji kwota transakcji %s zmienia znak (%s). Zweryfikuj ten wiersz.',
                $symbol,
                (string) $cash,
            ));
        }
    }

    /**
     * @return array{string, string, string, DateTimeImmutable, Decimal}|null
     *                                                                        [symbol, ISIN or '', currency, date, amount];
     *                                                                        null for a Total row
     */
    private static function payment(ActivityStatementRow $row): ?array
    {
        $currency = $row->get('currency');
        if ('' === $currency || str_starts_with($currency, 'Total')) {
            return null;
        }

        $description = $row->get('description');
        $symbol = '';
        $isin = '';
        if (1 === preg_match(self::PAYMENT_DESCRIPTION, $description, $match)) {
            $symbol = trim($match['symbol']);
            $isin = $match['isin'];
        }

        return [
            $symbol,
            $isin,
            strtoupper($currency),
            DateParser::parse($row->get('date')),
            NumberParser::parse($row->get('amount')),
        ];
    }

    /**
     * The listing country proposed for each FIFO queue, agreed across every
     * trade of that queue in the whole batch. Two exchanges naming different
     * countries withdraw the proposal: a blank country fails closed, a guess
     * would reach PIT/ZG.
     *
     * @param list<Trade> $trades
     *
     * @return array{array<string, string>, list<ImportMessage>}
     */
    private static function poolCountries(array $trades): array
    {
        /** @var array<string, array<string, array<string, true>>> $seen pool => country => exchange codes */
        $seen = [];
        foreach ($trades as $trade) {
            $instrument = $trade->instrument;
            if (null === $instrument || '' === $instrument->countryCode) {
                continue;
            }

            $seen[$trade->fifoPool][$instrument->countryCode][$instrument->exchangeCode] = true;
        }

        $countries = [];
        $messages = [];
        foreach ($seen as $pool => $byCountry) {
            if (1 === count($byCountry)) {
                $countries[$pool] = (string) array_key_first($byCountry);

                continue;
            }

            $codes = [];
            foreach ($byCountry as $country => $exchanges) {
                foreach (array_keys($exchanges) as $code) {
                    $codes[] = sprintf('%s -> %s', $code, $country);
                }
            }

            $messages[] = ImportMessage::warning('Import', sprintf(
                'Instrument %s ma w wyciągach różne giełdy notowania (%s), więc kraju nie da się ustalić '
                .'automatycznie. Uzupełnij kolumnę "Kraj" ręcznie.',
                strstr($pool, '@', true) ?: $pool,
                implode(', ', $codes),
            ));
        }

        return [$countries, $messages];
    }

    /**
     * Refuses timestamps whose ordering the statement cannot settle.
     *
     * IBKR reports execution time to the second, so a tie is rare - but a buy
     * and a sell of one queue at the same second, or two buys at different
     * costs, would leave FIFO to the order of rows in the file.
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
            $groups[$trade->fifoPool.'|'.$trade->date->format('Y-m-d H:i:s')][] = $trade;
        }

        $messages = [];
        foreach ($groups as $atInstant) {
            $buys = array_values(array_filter($atInstant, static fn (Trade $trade): bool => $trade->isBuy()));
            $sells = array_values(array_filter($atInstant, static fn (Trade $trade): bool => $trade->isSell()));
            $first = $atInstant[0];

            if ([] !== $buys && [] !== $sells) {
                $messages[] = ImportMessage::error('Import', sprintf(
                    'Zakup i sprzedaż %s mają ten sam czas wykonania (%s), więc nie da się bezpiecznie '
                    .'ustalić kolejności FIFO; rozlicz te operacje ręcznie.',
                    $first->symbol,
                    $first->date->format('Y-m-d H:i:s'),
                ));

                continue;
            }

            foreach (array_slice($buys, 1) as $other) {
                $left = $buys[0]->grossAmount->value()->multipliedBy($other->quantity->abs());
                $right = $other->grossAmount->value()->multipliedBy($buys[0]->quantity->abs());

                if (0 !== $left->compareTo($right)) {
                    $messages[] = ImportMessage::error('Import', sprintf(
                        'Kilka zakupów %s z różnym kosztem jednostkowym ma ten sam czas wykonania (%s). '
                        .'Kolejność wierszy zmieniałaby koszt FIFO, więc import przerwano; rozlicz je ręcznie.',
                        $first->symbol,
                        $first->date->format('Y-m-d H:i:s'),
                    ));

                    break;
                }
            }
        }

        return $messages;
    }
}

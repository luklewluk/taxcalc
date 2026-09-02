<?php

declare(strict_types=1);

namespace App\Import\Importer;

use App\Exception\InvalidCurrencyException;
use App\Exception\InvalidDateException;
use App\Exception\InvalidNumberException;
use App\Exception\InvalidRecordException;
use App\Fifo\FifoMatcher;
use App\Fifo\Trade;
use App\Import\CsvFormat;
use App\Import\CsvSource;
use App\Import\ImportMessage;
use App\Import\ImportResult;
use App\Import\Parser\DateParser;
use App\Import\Parser\NumberParser;
use App\Import\TradeIdScope;
use App\Model\ClosedPosition;
use App\Money\Amount;

/**
 * Reads the flat Interactive Brokers trade export:
 *
 *   AssetClass,Symbol,TradeDate,Quantity,TradePrice,NetCash,TransactionID,CurrencyPrimary
 *
 * Reading and matching are two separate steps. {@see CsvImportService} pools the
 * trades of every uploaded file and calls {@see matchTrades()} once, so a
 * position opened in the 2024 statement and closed in the 2025 one is matched
 * correctly. Calling {@see import()} on a single file still works and is what
 * the CLI convert command uses.
 *
 * `NetCash` is used rather than `TradePrice * Quantity` so broker commissions
 * end up in the acquisition cost, which is what Polish rules want on the buy
 * leg. This format reports no commission column of its own, so on the *sell*
 * leg the fee cannot be separated out of the settled cash: such positions keep
 * a przychód equal to that cash, and PIT-38 fields 22/23 are both understated
 * by the fee (the income, and therefore the tax, is unaffected).
 *
 * The format carries no country of origin, so imported rows are flagged for the
 * user to complete on the review screen.
 */
final class IbkrTradesImporter extends AbstractCsvImporter implements TradeSourceImporterInterface
{
    private const array REQUIRED = ['assetclass', 'symbol', 'tradedate', 'quantity', 'netcash', 'currencyprimary'];

    private const string STOCK_ASSET_CLASS = 'STK';

    public function __construct(
        private readonly FifoMatcher $fifoMatcher,
        int $maxRowsPerFile = self::DEFAULT_MAX_ROWS_PER_FILE,
    ) {
        parent::__construct($maxRowsPerFile);
    }

    public function supports(CsvFormat $format): bool
    {
        return CsvFormat::IbkrTrades === $format;
    }

    public function tradeIdScope(): TradeIdScope
    {
        // IBKR's TransactionID is account-wide and identifies one execution.
        return TradeIdScope::Fill;
    }

    public function tradeIdLabel(): string
    {
        return 'TransactionID';
    }

    public function import(CsvSource $source): ImportResult
    {
        $extraction = $this->extractTrades($source);

        return $this->matchTrades($extraction->trades)->withMessages($extraction->messages);
    }

    public function extractTrades(CsvSource $source): TradeExtraction
    {
        [$rows, $fatal, $notices] = $this->readRows($source, self::REQUIRED);
        if ([] !== $fatal) {
            return new TradeExtraction([], $fatal);
        }

        $messages = $notices;
        $skippedAssetClasses = [];
        $trades = [];

        foreach ($rows as $index => $row) {
            $line = self::lineNumber($index);
            $assetClass = strtoupper($row['assetclass'] ?? '');

            if (self::STOCK_ASSET_CLASS !== $assetClass) {
                if ('' !== $assetClass) {
                    $skippedAssetClasses[$assetClass] = ($skippedAssetClasses[$assetClass] ?? 0) + 1;
                }

                continue;
            }

            try {
                $currency = strtoupper($row['currencyprimary'] ?? '');
                $quantity = NumberParser::parse($row['quantity'] ?? '');
                $netCash = NumberParser::parse($row['netcash'] ?? '');
                $tradePrice = self::optionalTradePrice($row, $currency);
                $date = DateParser::parse($row['tradedate'] ?? '');
                $symbol = trim($row['symbol'] ?? '');

                if ('' === $symbol) {
                    $messages[] = ImportMessage::error($source->name, 'Brak symbolu instrumentu.', $line);

                    continue;
                }

                if ($quantity->isZero()) {
                    $messages[] = ImportMessage::info($source->name, 'Pominięto wiersz z zerową liczbą sztuk.', $line);

                    continue;
                }

                $externalId = self::externalId($row);
                $trades[] = new Trade(
                    $symbol,
                    $date,
                    $quantity,
                    Amount::fromDecimal($netCash->abs(), $currency),
                    $externalId,
                    sprintf('%s (%s)', $source->name, CsvFormat::IbkrTrades->label()),
                    externalIdReported: null !== $externalId,
                    unitPrice: $tradePrice,
                    broker: 'IBKR',
                    fifoPool: $symbol.'@'.$currency,
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $messages[] = ImportMessage::error($source->name, $e->getMessage(), $line);
            }
        }

        foreach ($skippedAssetClasses as $assetClass => $count) {
            $messages[] = ImportMessage::info(
                $source->name,
                sprintf('Pominięto %d wiersz(y) klasy aktywów "%s" - obsługiwane są wyłącznie akcje i ETF-y (STK).', $count, $assetClass),
            );
        }

        return new TradeExtraction($trades, $messages);
    }

    public function matchTrades(array $trades): ImportResult
    {
        if ([] === $trades) {
            return new ImportResult();
        }

        $fifo = $this->fifoMatcher->match($trades);

        $positions = [];
        $messages = [];

        foreach ($fifo->matches as $match) {
            $symbol = $match->symbol;
            $currency = $match->buyCost->currency();

            // A zero-value leg only becomes visible once FIFO has prorated it,
            // so the record invariants are enforced here rather than per row.
            try {
                $positions[] = new ClosedPosition(
                    $symbol,
                    // Not present in this export - completed by the user.
                    '',
                    $currency,
                    $match->buyDate,
                    $match->buyCost,
                    $match->sellDate,
                    $match->sellProceeds,
                    $match->quantity,
                    PositionSource::describe($match->buySource, $match->sellSource, CsvFormat::IbkrTrades),
                    $match->lineageKey(),
                    $match->buyCommission,
                    $match->sellCommission,
                    $match->buyAutoFx,
                    $match->sellAutoFx,
                    $match->broker,
                    $symbol,
                    $match->buyTradeId,
                    $match->sellTradeId,
                    $match->buyUnitPrice,
                    $match->sellUnitPrice,
                );
            } catch (InvalidRecordException $e) {
                $messages[] = ImportMessage::error('Import', sprintf(
                    'Pozycja %s (zakup %s, sprzedaż %s): %s',
                    $symbol,
                    $match->buyDate->format('Y-m-d'),
                    $match->sellDate->format('Y-m-d'),
                    $e->getMessage(),
                ));
            }
        }

        foreach ($fifo->unmatchedSells as $unmatched) {
            $symbol = $unmatched->symbol;

            // Fatal, not a warning: without its buy leg a sale has no cost
            // basis, so its whole proceeds would read as gain. Settling the
            // other positions and quietly leaving this one out produces a
            // return that looks complete and is not.
            $messages[] = ImportMessage::error('Import', sprintf(
                'Sprzedaż %s z dnia %s (%s szt.) nie ma pokrycia w zakupach z wgranych plików, '
                .'więc nie da się ustalić kosztu nabycia. Dograj wcześniejsze zestawienie transakcji '
                .'albo uzupełnij tę pozycję ręcznie w formacie własnym.',
                $symbol,
                $unmatched->date->format('Y-m-d'),
                (string) $unmatched->quantity,
            ));
        }

        if ([] !== $positions) {
            $messages[] = ImportMessage::warning(
                'Import',
                'Format transakcji IBKR nie zawiera kraju uzyskania dochodu - uzupełnij kolumnę "Kraj" '
                .'przed obliczeniem, aby poprawnie wypełnić załącznik PIT/ZG.',
            );
        }

        return new ImportResult($positions, [], $messages);
    }

    /**
     * @param array<string, string> $row
     */
    private static function externalId(array $row): ?string
    {
        $id = trim($row['transactionid'] ?? '');

        return '' === $id ? null : $id;
    }

    /** @param array<string, string> $row */
    private static function optionalTradePrice(array $row, string $currency): ?Amount
    {
        $raw = trim($row['tradeprice'] ?? '');
        if ('' === $raw) {
            return null;
        }

        $price = Amount::fromDecimal(NumberParser::parse($raw), $currency);
        if (!$price->isPositive()) {
            throw InvalidRecordException::amountMustBePositive('TradePrice', $price);
        }

        return $price;
    }
}

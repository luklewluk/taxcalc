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
 * end up in the cost basis and in the proceeds, which is what Polish rules
 * require.
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

                $trades[] = new Trade(
                    // Currency is part of the identity: the same ticker quoted in
                    // two currencies must not share a FIFO queue.
                    $symbol.'@'.$currency,
                    $date,
                    $quantity,
                    Amount::fromDecimal($netCash->abs(), $currency),
                    self::externalId($row),
                    $source->name,
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
            [$symbol, $currency] = self::splitKey($match->symbol);

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
            [$symbol] = self::splitKey($unmatched->symbol);

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
     * @return array{string, string} symbol and currency
     */
    private static function splitKey(string $key): array
    {
        $at = strrpos($key, '@');

        return false === $at
            ? [$key, '']
            : [substr($key, 0, $at), substr($key, $at + 1)];
    }

    /**
     * @param array<string, string> $row
     */
    private static function externalId(array $row): ?string
    {
        $id = trim($row['transactionid'] ?? '');

        return '' === $id ? null : $id;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Fifo\FifoMatcher;
use App\Import\CsvImportService;
use App\Import\CsvSource;
use App\Import\FormatDetector;
use App\Import\Importer\IbkrActivityDividendsImporter;
use App\Import\Importer\IbkrDividendDetailImporter;
use App\Import\Importer\IbkrTradesImporter;
use App\Import\Importer\NormalizedDividendsImporter;
use App\Import\Importer\NormalizedPositionsImporter;
use App\Import\ImportResult;
use App\Tax\TaxRates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Interactive Brokers statements are downloaded per year, so the buy leg of a
 * position routinely lives in a different file from the sell leg. FIFO must
 * therefore run once over every uploaded trade file, not once per file.
 */
#[CoversClass(CsvImportService::class)]
#[CoversClass(IbkrTradesImporter::class)]
final class CrossSourceImportTest extends TestCase
{
    private const string TRADE_HEADER =
        '"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"';

    public function testBuyInOneFileIsMatchedAgainstASellInAnother(): void
    {
        $result = $this->import([
            'trades-2024.csv' => self::trades(['"STK","AAA","20240101","10","100","-1000.00","1001","USD"']),
            'trades-2025.csv' => self::trades(['"STK","AAA","20250601","-10","150","1500.00","1002","USD"']),
        ]);

        self::assertCount(1, $result->positions, implode(' | ', $result->warnings()));

        $position = $result->positions[0];
        self::assertSame('2024-01-01', $position->buyDate->format('Y-m-d'));
        self::assertSame('2025-06-01', $position->sellDate->format('Y-m-d'));
        self::assertSame('1000.00', (string) $position->buyAmount->value());
        self::assertSame('1500.00', (string) $position->sellAmount->value());
        self::assertSame(2025, $position->taxYear());

        // Nothing may be reported as unmatched any more.
        self::assertStringNotContainsString('nie ma pokrycia', implode(' ', $result->warnings()));
    }

    public function testFileOrderDoesNotMatter(): void
    {
        $result = $this->import([
            'trades-2025.csv' => self::trades(['"STK","AAA","20250601","-10","150","1500.00","1002","USD"']),
            'trades-2024.csv' => self::trades(['"STK","AAA","20240101","10","100","-1000.00","1001","USD"']),
        ]);

        self::assertCount(1, $result->positions);
        self::assertSame('2024-01-01', $result->positions[0]->buyDate->format('Y-m-d'));
    }

    public function testOverlappingStatementsAreDeduplicatedByTransactionIdBeforeMatching(): void
    {
        // Annual statements overlap: the 2025 file repeats the 2024 opening trade.
        $result = $this->import([
            'trades-2024.csv' => self::trades([
                '"STK","AAA","20240101","10","100","-1000.00","1001","USD"',
            ]),
            'trades-2025.csv' => self::trades([
                '"STK","AAA","20240101","10","100","-1000.00","1001","USD"',
                '"STK","AAA","20250601","-10","150","1500.00","1002","USD"',
            ]),
        ]);

        // The repeated buy must not create a second lot and must not leave an
        // open position behind.
        self::assertCount(1, $result->positions);
        self::assertSame('1000.00', (string) $result->positions[0]->buyAmount->value());
        self::assertSame('10', (string) $result->positions[0]->quantity);
    }

    public function testConflictingContentForTheSameTransactionIdAbortsImport(): void
    {
        $result = $this->import([
            'a.csv' => self::trades([
                '"STK","AAA","20240101","1","10","-10.00","SAME","USD"',
            ]),
            'b.csv' => self::trades([
                '"STK","AAA","20240101","1","20","-20.00","SAME","USD"',
                '"STK","AAA","20240601","-1","30","30.00","SELL","USD"',
            ]),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('SAME', implode(' ', $result->errors()));
    }

    public function testSameTransactionIdOnDifferentSymbolsIsAlsoAConflict(): void
    {
        $result = $this->import([
            'a.csv' => self::trades([
                '"STK","AAA","20240101","1","10","-10.00","SAME","USD"',
                '"STK","BBB","20240101","1","20","-20.00","SAME","USD"',
            ]),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
    }

    public function testUploadingTheExactSameTradeFileTwiceChangesNothing(): void
    {
        $file = self::trades([
            '"STK","AAA","20240101","10","100","-1000.00","1001","USD"',
            '"STK","AAA","20250601","-10","150","1500.00","1002","USD"',
        ]);

        $once = $this->import(['a.csv' => $file]);
        $twice = $this->import(['a.csv' => $file, 'b.csv' => $file]);

        self::assertCount(1, $once->positions);
        self::assertCount(1, $twice->positions);
        self::assertSame(
            (string) $once->positions[0]->sellAmount->value(),
            (string) $twice->positions[0]->sellAmount->value(),
        );
    }

    public function testIdenticalFillsWithDistinctTransactionIdsAreBothKept(): void
    {
        // Two economically identical fills on the same day, and two identical
        // sells. These are four real trades, not duplicates.
        $result = $this->import([
            'trades.csv' => self::trades([
                '"STK","AAA","20240101","1","10","-10.00","2001","USD"',
                '"STK","AAA","20240101","1","10","-10.00","2002","USD"',
                '"STK","AAA","20240601","-1","15","15.00","2003","USD"',
                '"STK","AAA","20240601","-1","15","15.00","2004","USD"',
            ]),
        ]);

        self::assertCount(2, $result->positions);
        self::assertSame('10.00', (string) $result->positions[0]->buyAmount->value());
        self::assertSame('10.00', (string) $result->positions[1]->buyAmount->value());
    }

    public function testDistinctFillsSurviveEvenWhenTheSameFileIsUploadedTwice(): void
    {
        $file = self::trades([
            '"STK","AAA","20240101","1","10","-10.00","2001","USD"',
            '"STK","AAA","20240101","1","10","-10.00","2002","USD"',
            '"STK","AAA","20240601","-1","15","15.00","2003","USD"',
            '"STK","AAA","20240601","-1","15","15.00","2004","USD"',
        ]);

        $result = $this->import(['a.csv' => $file, 'b.csv' => $file]);

        self::assertCount(2, $result->positions);
    }

    public function testTradesInDifferentCurrenciesStayIndependent(): void
    {
        $result = $this->import([
            'usd.csv' => self::trades([
                '"STK","AAA","20240101","1","10","-10.00","3001","USD"',
                '"STK","AAA","20240601","-1","15","15.00","3002","USD"',
            ]),
            'eur.csv' => self::trades([
                '"STK","AAA","20240102","1","10","-20.00","3003","EUR"',
                '"STK","AAA","20240602","-1","15","25.00","3004","EUR"',
            ]),
        ]);

        self::assertCount(2, $result->positions);
        $currencies = array_map(static fn ($p): string => $p->currency, $result->positions);
        sort($currencies);
        self::assertSame(['EUR', 'USD'], $currencies);
    }

    public function testTradesWithoutTransactionIdsStillImportButAreNotIdDeduplicated(): void
    {
        $header = '"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","CurrencyPrimary"';
        $file = $header."\n"
            .'"STK","AAA","20240101","1","10","-10.00","USD"'."\n"
            .'"STK","AAA","20240601","-1","15","15.00","USD"'."\n";

        $result = $this->import(['a.csv' => $file]);

        self::assertCount(1, $result->positions);
    }

    /**
     * A sale whose purchase is in none of the uploaded files has no cost basis,
     * so it is reported once - and fatally, because settling everything else and
     * leaving that one out yields a return that looks complete and understates
     * nothing visible.
     */
    public function testUnmatchedSellAcrossAllFilesIsReportedOnceAndTheRestImports(): void
    {
        $result = $this->import([
            'a.csv' => self::trades(['"STK","AAA","20250601","-5","15","75.00","4001","USD"']),
            'b.csv' => self::trades(['"STK","BBB","20240101","1","10","-10.00","4002","USD"']),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->trades, 'Every transaction reaches the workbench.');

        $unmatched = array_filter(
            $result->warnings(),
            static fn (string $e): bool => str_contains($e, 'nie ma pokrycia'),
        );
        self::assertCount(1, $unmatched);
        self::assertStringContainsString('AAA', implode(' ', $unmatched));
    }

    public function testNormalizedPositionsKeepContentOnlyDeduplication(): void
    {
        $csv = "name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n"
            ."AAA,US,USD,2024-01-01,10.00,2024-06-01,15.00\n";

        $result = $this->import(['a.csv' => $csv, 'b.csv' => $csv]);

        self::assertCount(1, $result->positions);
        self::assertStringContainsString('duplikat', mb_strtolower(implode(' ', $result->warnings())));
    }

    /**
     * @param array<string, string> $files
     */
    private function import(array $files): ImportResult
    {
        $sources = [];
        foreach ($files as $name => $content) {
            $sources[] = new CsvSource($name, $content);
        }

        return self::service()->import($sources);
    }

    /**
     * @param list<string> $rows
     */
    private static function trades(array $rows): string
    {
        return self::TRADE_HEADER."\n".implode("\n", $rows)."\n";
    }

    private static function service(): CsvImportService
    {
        return new CsvImportService(
            new FormatDetector(),
            [
                new IbkrTradesImporter(new FifoMatcher()),
                new IbkrActivityDividendsImporter(),
                new IbkrDividendDetailImporter(),
                new NormalizedPositionsImporter(),
                new NormalizedDividendsImporter(),
            ],
            new TaxRates(),
        );
    }
}

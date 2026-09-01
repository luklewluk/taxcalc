<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Fifo\FifoMatcher;
use App\Import\CsvImportService;
use App\Import\CsvSource;
use App\Import\FormatDetector;
use App\Import\Importer\DegiroAccountImporter;
use App\Import\Importer\DegiroTransactionsImporter;
use App\Import\Importer\IbkrActivityDividendsImporter;
use App\Import\Importer\IbkrDividendDetailImporter;
use App\Import\Importer\IbkrTradesImporter;
use App\Import\Importer\NormalizedDividendsImporter;
use App\Import\Importer\NormalizedPositionsImporter;
use App\Tax\TaxRates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ten 5 MB uploads must not be able to grow an unbounded array of domain
 * objects, so the caps have to bite while reading - before exchange-rate
 * lookups and tax calculation, not only when a posted form is remapped.
 */
#[CoversClass(CsvImportService::class)]
final class ImportLimitsTest extends TestCase
{
    public function testTotalRecordCountIsCappedWithAClearError(): void
    {
        $result = $this->service(maxRecords: 5)->import([
            new CsvSource('d.csv', self::dividends(20)),
        ]);

        // A partial tax dataset is more dangerous than no result at all.
        self::assertSame([], $result->dividends);
        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('limit 5', implode(' ', $result->errors()));
    }

    public function testCapAppliesAcrossPositionsAndDividendsTogether(): void
    {
        $result = $this->service(maxRecords: 4)->import([
            new CsvSource('p.csv', self::positions(3)),
            new CsvSource('d.csv', self::dividends(3)),
        ]);

        self::assertSame([], $result->positions);
        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testDataUnderTheCapIsUntouched(): void
    {
        $result = $this->service(maxRecords: 100)->import([
            new CsvSource('d.csv', self::dividends(20)),
        ]);

        self::assertCount(20, $result->dividends);
        self::assertSame([], $result->errors());
    }

    public function testRowsPerFileAreCappedWhileReadingSoAHugeFileCannotBeFullyMaterialised(): void
    {
        $importer = new NormalizedDividendsImporter(new TaxRates(), maxRowsPerFile: 10);

        $result = $importer->import(new CsvSource('d.csv', self::dividends(50)));

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('10', implode(' ', $result->errors()));
    }

    public function testTradeFileRowsAreCappedTooBeforeFifoRuns(): void
    {
        $rows = ['"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"'];
        for ($i = 0; $i < 40; ++$i) {
            $rows[] = sprintf('"STK","AAA","20240101","1","10","-10.00","%d","USD"', 5000 + $i);
        }

        $importer = new IbkrTradesImporter(new FifoMatcher(), maxRowsPerFile: 10);
        $extraction = $importer->extractTrades(new CsvSource('t.csv', implode("\n", $rows)."\n"));

        self::assertSame([], $extraction->trades);
        self::assertNotEmpty($extraction->messages);
    }

    public function testSectionedDividendDetailFileIsCappedToo(): void
    {
        $lines = [
            'DividendDetail,Header,DataDiscriminator,Currency,Symbol,Conid,Country,ReportDate,ExDate,Shares,'
            .'RevenueComponent,QualifiedIndicator,Gross,GrossInBase,GrossInUSD,Withhold,WithholdInBase,WithholdInUSD',
        ];
        for ($i = 0; $i < 40; ++$i) {
            $lines[] = sprintf(
                'DividendDetail,Data,Summary,USD,S%d,%d,US,20250213,20250207,10,,,%d.00,1,1,0,0,0,',
                $i,
                $i,
                $i + 1,
            );
        }

        $result = (new IbkrDividendDetailImporter(maxRowsPerFile: 10))
            ->import(new CsvSource('detail.csv', implode("\n", $lines)."\n"));

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testDegiroTransactionFileRowsAreCappedBeforeFifoRuns(): void
    {
        $rows = [
            'Date,Time,Product,ISIN,Reference Exchange,Execution Venue,Quantity,Price,,Value,,'
            .'Transaction and/or third,,Total,,Order ID',
        ];
        for ($i = 0; $i < 40; ++$i) {
            $rows[] = sprintf(
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,1,10.00,USD,-10.00,USD,0.00,USD,-10.00,USD,o-%d',
                $i,
            );
        }

        $importer = new DegiroTransactionsImporter(new FifoMatcher(), maxRowsPerFile: 10);
        $extraction = $importer->extractTrades(new CsvSource('degiro.csv', implode("\n", $rows)."\n"));

        self::assertSame([], $extraction->trades);
        self::assertNotEmpty($extraction->messages);
        self::assertStringContainsString('10', implode(' ', array_map(
            static fn ($m): string => $m->message,
            $extraction->messages,
        )));
    }

    public function testDegiroAccountStatementRowsAreCappedToo(): void
    {
        $rows = ['Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id'];
        for ($i = 0; $i < 40; ++$i) {
            $rows[] = sprintf(
                '13-02-2025,06:32,13-02-2025,ALFA CORP,US000ALFA001,Dividend,,USD,%d.00,USD,100.00,',
                $i + 1,
            );
        }

        $result = (new DegiroAccountImporter(maxRowsPerFile: 10))
            ->import(new CsvSource('rachunek.csv', implode("\n", $rows)."\n"));

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testTheRawTradeCapCountsEveryBrokerTogether(): void
    {
        $degiro = 'Date,Time,Product,ISIN,Reference Exchange,Execution Venue,Quantity,Price,,Value,,'
            ."Transaction and/or third,,Total,,Order ID\n";
        for ($i = 0; $i < 4; ++$i) {
            $degiro .= sprintf(
                "03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,1,10.00,USD,-10.00,USD,0.00,USD,-10.00,USD,o-%d\n",
                $i,
            );
        }

        $ibkr = '"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"'."\n";
        for ($i = 0; $i < 4; ++$i) {
            $ibkr .= sprintf('"STK","AAA","20240101","1","10","-10.00","%d","USD"'."\n", 7000 + $i);
        }

        $result = $this->service(maxRecords: 5)->import([
            new CsvSource('degiro.csv', $degiro),
            new CsvSource('ibkr.csv', $ibkr),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
    }

    private function service(int $maxRecords): CsvImportService
    {
        return new CsvImportService(
            new FormatDetector(),
            [
                new IbkrTradesImporter(new FifoMatcher()),
                new IbkrActivityDividendsImporter(),
                new IbkrDividendDetailImporter(),
                new DegiroTransactionsImporter(new FifoMatcher()),
                new DegiroAccountImporter(),
                new NormalizedPositionsImporter(),
                new NormalizedDividendsImporter(),
            ],
            new TaxRates(),
            $maxRecords,
        );
    }

    private static function dividends(int $count): string
    {
        $csv = "name,country,currency,date,amount,tax_paid\n";
        for ($i = 0; $i < $count; ++$i) {
            $csv .= sprintf("S%d,US,USD,2025-04-02,%d.00,0\n", $i, $i + 1);
        }

        return $csv;
    }

    private static function positions(int $count): string
    {
        $csv = "name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n";
        for ($i = 0; $i < $count; ++$i) {
            $csv .= sprintf("S%d,US,USD,2024-01-01,%d.00,2025-06-01,%d.00\n", $i, $i + 1, $i + 2);
        }

        return $csv;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Fifo\FifoMatcher;
use App\Import\CsvImportService;
use App\Import\CsvSource;
use App\Import\FormatDetector;
use App\Import\Importer\DegiroAccountImporter;
use App\Import\Importer\DegiroTransactionsImporter;
use App\Import\Importer\IbkrActivityStatementImporter;
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
        // Two raw trades (one closed position) and three dividends: each file
        // alone is under the cap, together they are not.
        $result = $this->service(maxRecords: 4)->import([
            new CsvSource('p.csv', self::positions(1)),
            new CsvSource('d.csv', self::dividends(3)),
        ]);

        self::assertSame([], $result->positions);
        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('limit 4 rekordów', implode(' ', $result->errors()));
    }

    public function testDataUnderTheCapIsUntouched(): void
    {
        $result = $this->service(maxRecords: 100)->import([
            new CsvSource('d.csv', self::dividends(20)),
        ]);

        self::assertCount(20, $result->dividends);
        self::assertSame([], $result->errors());
    }

    public function testSectionedActivityStatementIsCappedToo(): void
    {
        $lines = [
            'Statement,Header,Field Name,Field Value',
            'Statement,Data,Title,Activity Statement',
            'Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,C. Price,'
            .'Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code',
        ];
        for ($i = 0; $i < 40; ++$i) {
            $lines[] = sprintf(
                'Trades,Data,Order,Stocks,USD,AAA,"2025-01-02, 10:%02d:00",1,10,10,-10,0,10,0,0,O',
                $i,
            );
        }
        $lines[] = 'Dividends,Header,Currency,Date,Description,Amount';
        for ($i = 0; $i < 40; ++$i) {
            $lines[] = sprintf(
                'Dividends,Data,USD,2025-02-13,S%1$d(US%1$09d1) Cash Dividend USD 0.10 per Share (Ordinary Dividend),%2$d.00',
                $i,
                $i + 1,
            );
        }

        $result = (new CsvImportService(
            new FormatDetector(),
            [new IbkrActivityStatementImporter(new FifoMatcher(), maxRowsPerFile: 10)],
            new TaxRates(),
        ))->import([new CsvSource('as.csv', implode("\n", $lines)."\n")]);

        self::assertSame([], $result->trades);
        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('10', implode(' ', $result->errors()));
    }

    /**
     * The cap is per file, not per section: a statement with a handful of
     * trades and more dividend rows than the cap must not lose its dividends
     * in silence while the trades import.
     */
    public function testAStatementOverTheCapOnlyInItsDividendsIsRejectedToo(): void
    {
        $lines = [
            'Statement,Header,Field Name,Field Value',
            'Statement,Data,Title,Activity Statement',
            'Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,C. Price,'
            .'Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code',
            'Trades,Data,Order,Stocks,USD,AAA,"2025-01-02, 10:00:00",1,10,10,-10,0,10,0,0,O',
            'Dividends,Header,Currency,Date,Description,Amount',
        ];
        for ($i = 0; $i < 40; ++$i) {
            $lines[] = sprintf(
                'Dividends,Data,USD,2025-02-13,S%1$d(US%1$09d1) Cash Dividend USD 0.10 per Share (Ordinary Dividend),%2$d.00',
                $i,
                $i + 1,
            );
        }

        $result = (new CsvImportService(
            new FormatDetector(),
            [new IbkrActivityStatementImporter(new FifoMatcher(), maxRowsPerFile: 10)],
            new TaxRates(),
        ))->import([new CsvSource('as.csv', implode("\n", $lines)."\n")]);

        self::assertSame([], $result->trades);
        self::assertSame([], $result->dividends);
        self::assertStringContainsString('10', implode(' ', $result->errors()));
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

        $ibkr = "Statement,Header,Field Name,Field Value\n"
            ."Statement,Data,Title,Activity Statement\n"
            ."Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,C. Price,Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code\n";
        for ($i = 0; $i < 4; ++$i) {
            $ibkr .= sprintf(
                "Trades,Data,Order,Stocks,USD,AAA,\"2024-01-02, 10:0%d:00\",1,10,10,-10,0,10,0,0,O\n",
                $i,
            );
        }
        $ibkr .= "Financial Instrument Information,Header,Asset Category,Symbol,Description,Conid,Security ID,Underlying,Listing Exch,Multiplier,Type,Code\n"
            ."Financial Instrument Information,Data,Stocks,AAA,ALFA CORP,1001,US000ALFA001,AAA,NASDAQ,1,COMMON,\n";

        $result = $this->service(maxRecords: 5)->import([
            new CsvSource('degiro.csv', $degiro),
            new CsvSource('ibkr.csv', $ibkr),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('limit 5 surowych transakcji', implode(' ', $result->errors()));
    }

    private function service(int $maxRecords): CsvImportService
    {
        return new CsvImportService(
            new FormatDetector(),
            [
                new IbkrActivityStatementImporter(new FifoMatcher()),
                new DegiroTransactionsImporter(new FifoMatcher()),
                new DegiroAccountImporter(),
            ],
            new TaxRates(),
            $maxRecords,
        );
    }

    /**
     * One DEGIRO payment per ISIN, so every row is its own dividend.
     */
    private static function dividends(int $count): string
    {
        $csv = "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n";
        for ($i = 0; $i < $count; ++$i) {
            $csv .= sprintf('02-04-2025,06:32,02-04-2025,S%1$d,US%1$09d1,Dividend,,USD,%2$d.00,USD,100.00,'."\n", $i, $i + 1);
        }

        return $csv;
    }

    /**
     * One buy and one sell per instrument, each pair its own closed position.
     */
    private static function positions(int $count): string
    {
        $csv = "Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,"
            ."Transaction and/or third party costs,,Total,,Order ID\n";
        for ($i = 0; $i < $count; ++$i) {
            $csv .= sprintf(
                '01-01-2024,10:00,S%1$d,XS%1$09d1,,,1,%2$d.00,USD,-%2$d.00,USD,-%2$d.00,USD,,,,-%2$d.00,USD,b-%1$d'."\n"
                .'01-06-2025,10:00,S%1$d,XS%1$09d1,,,-1,%3$d.00,USD,%3$d.00,USD,%3$d.00,USD,,,,%3$d.00,USD,s-%1$d'."\n",
                $i,
                $i + 1,
                $i + 2,
            );
        }

        return $csv;
    }
}

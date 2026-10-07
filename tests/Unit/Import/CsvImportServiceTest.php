<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Fifo\FifoMatcher;
use App\Import\CsvImportService;
use App\Import\CsvSource;
use App\Import\FormatDetector;
use App\Import\MessageLevel;
use App\Import\Importer\IbkrActivityDividendsImporter;
use App\Import\Importer\IbkrActivityStatementImporter;
use App\Import\Importer\IbkrDividendDetailImporter;
use App\Import\Importer\IbkrTradesImporter;
use App\Import\Importer\NormalizedDividendsImporter;
use App\Import\Importer\NormalizedPositionsImporter;
use App\Tax\TaxRates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvImportService::class)]
final class CsvImportServiceTest extends TestCase
{
    private CsvImportService $service;

    protected function setUp(): void
    {
        $this->service = new CsvImportService(
            new FormatDetector(),
            [
                new IbkrTradesImporter(new FifoMatcher()),
                new IbkrActivityDividendsImporter(),
                new IbkrDividendDetailImporter(),
                new IbkrActivityStatementImporter(new FifoMatcher()),
                new NormalizedPositionsImporter(),
                new NormalizedDividendsImporter(),
            ],
            new TaxRates(),
        );
    }

    public function testRoutesEachFileToTheImporterThatMatchesItsFormat(): void
    {
        $result = $this->service->import([
            new CsvSource('trades.csv', self::ibkrTrades()),
            new CsvSource('dividends.csv', self::normalizedDividends()),
        ]);

        self::assertCount(1, $result->positions);
        self::assertCount(1, $result->dividends);
    }

    public function testUnrecognisedFileAbortsTheWholeMultiFileImport(): void
    {
        $result = $this->service->import([
            new CsvSource('junk.csv', "foo,bar\n1,2\n"),
            new CsvSource('dividends.csv', self::normalizedDividends()),
        ]);

        self::assertSame([], $result->positions);
        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('junk.csv', implode(' ', $result->errors()));
    }

    public function testTheSameFileUploadedTwiceDoesNotDoubleTheNumbers(): void
    {
        $result = $this->service->import([
            new CsvSource('a.csv', self::normalizedDividends()),
            new CsvSource('b.csv', self::normalizedDividends()),
        ]);

        self::assertCount(1, $result->dividends);
        self::assertNotEmpty($result->warnings());
        self::assertStringContainsString('duplikat', mb_strtolower(implode(' ', $result->warnings())));
    }

    public function testGenuinelyDifferentRowsAreBothKept(): void
    {
        $second = "name,country,currency,date,amount,tax_paid\nAAA,US,USD,2024-06-11,10.00,1.50\n";

        $result = $this->service->import([
            new CsvSource('a.csv', self::normalizedDividends()),
            new CsvSource('b.csv', $second),
        ]);

        self::assertCount(2, $result->dividends);
    }

    public function testEachRowRemembersWhichFileAndFormatItCameFrom(): void
    {
        $result = $this->service->import([new CsvSource('trades.csv', self::ibkrTrades())]);

        self::assertStringContainsString('trades.csv', $result->positions[0]->source);
    }

    public function testEmptyUploadListProducesAnEmptyResult(): void
    {
        $result = $this->service->import([]);

        self::assertSame([], $result->positions);
        self::assertSame([], $result->dividends);
        self::assertSame([], $result->messages);
    }

    public function testFilesAreProcessedInMemoryWithoutTouchingTheFilesystem(): void
    {
        $before = self::projectFileCount();
        $this->service->import([new CsvSource('trades.csv', self::ibkrTrades())]);

        self::assertSame($before, self::projectFileCount());
    }

    public function testAnActivityStatementFeedsBothTradesAndDividends(): void
    {
        $result = $this->service->import([new CsvSource('as.csv', self::activityStatement())]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertCount(1, $result->dividends);
        self::assertCount(2, $result->trades);
    }

    public function testAnUnreadableActivityStatementIsReportedOnce(): void
    {
        $result = (new CsvImportService(
            new FormatDetector(),
            [new IbkrActivityStatementImporter(new FifoMatcher(), 1)],
            new TaxRates(),
        ))->import([new CsvSource('as.csv', self::activityStatement())]);

        self::assertCount(1, $result->errors());
    }

    public function testOverlappingActivityStatementsSettleEachTradeOnce(): void
    {
        $result = $this->service->import([
            new CsvSource('a.csv', self::activityStatement()),
            new CsvSource('b.csv', self::activityStatement()),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertCount(2, $result->trades);
    }

    public function testFlexTradesAndActivityStatementTradesCannotBeMixed(): void
    {
        $result = $this->service->import([
            new CsvSource('flex.csv', self::ibkrTrades()),
            new CsvSource('as.csv', self::activityStatement()),
        ]);

        self::assertSame([], $result->positions);
        self::assertStringContainsString('jeden format', implode("\n", $result->errors()));
    }

    public function testActivityStatementDividendsNextToAnotherIbkrDividendExportAreFlagged(): void
    {
        $result = $this->service->import([
            new CsvSource('as.csv', self::activityStatement()),
            new CsvSource('detail.csv', self::dividendDetail()),
        ]);

        $review = array_filter($result->messages, static fn ($m): bool => MessageLevel::Review === $m->level);
        self::assertNotSame([], $review);
        self::assertStringContainsString('Dividend Detail', implode("\n", array_map(static fn ($m): string => $m->message, $review)));
    }

    private static function activityStatement(): string
    {
        return "Statement,Header,Field Name,Field Value\n"
            ."Statement,Data,Title,Activity Statement\n"
            ."Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,C. Price,Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code\n"
            ."Trades,Data,Order,Stocks,USD,AAA,\"2024-01-02, 10:00:00\",1,10,10,-10,-1,11,0,0,O\n"
            ."Trades,Data,Order,Stocks,USD,AAA,\"2024-06-03, 10:00:00\",-1,15,15,15,-1,-11,3,0,C\n"
            ."Dividends,Header,Currency,Date,Description,Amount\n"
            ."Dividends,Data,USD,2024-06-10,AAA(US000ALFA001) Cash Dividend USD 1.00 per Share (Ordinary Dividend),1\n"
            ."Financial Instrument Information,Header,Asset Category,Symbol,Description,Conid,Security ID,Underlying,Listing Exch,Multiplier,Type,Code\n"
            ."Financial Instrument Information,Data,Stocks,AAA,ALFA CORP,1001,US000ALFA001,AAA,NASDAQ,1,COMMON,\n";
    }

    private static function dividendDetail(): string
    {
        return "DividendDetail,Header,DataDiscriminator,Currency,Symbol,Conid,Country,ReportDate,ExDate,Shares,RevenueComponent,QualifiedIndicator,Gross,GrossInBase,GrossInUSD,Withhold,WithholdInBase,WithholdInUSD\n"
            ."DividendDetail,Data,Summary,USD,BBB,1,US,20240610,20240601,1,,,2,2,2,-0.3,-0.3,-0.3,\n";
    }

    private static function ibkrTrades(): string
    {
        return '"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"'."\n"
            .'"STK","AAA","20240101","1","10","-10.00","1","USD"'."\n"
            .'"STK","AAA","20240601","-1","15","15.00","2","USD"'."\n";
    }

    private static function normalizedDividends(): string
    {
        return "name,country,currency,date,amount,tax_paid\nAAA,US,USD,2024-06-10,10.00,1.50\n";
    }

    private static function projectFileCount(): int
    {
        $dir = dirname(__DIR__, 3);
        $count = 0;
        foreach (['examples', 'templates', 'public'] as $sub) {
            if (!is_dir($dir.'/'.$sub)) {
                continue;
            }
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir.'/'.$sub)) as $file) {
                if ($file->isFile()) {
                    ++$count;
                }
            }
        }

        return $count;
    }
}

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

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
 * The two Interactive Brokers dividend exports describe the same payments. The
 * activity export has no country, the Dividend Detail export does, so a naive
 * content fingerprint sees them as different records and taxes the dividend
 * twice.
 */
#[CoversClass(CsvImportService::class)]
final class DividendOverlapTest extends TestCase
{
    private const string ACTIVITY_HEADER =
        '"CurrencyPrimary","Symbol","Multiplier","Date/Time","Amount","Type","TransactionID"';

    private const string DETAIL_HEADER =
        'DividendDetail,Header,DataDiscriminator,Currency,Symbol,Conid,Country,ReportDate,ExDate,Shares,'
        .'RevenueComponent,QualifiedIndicator,Gross,GrossInBase,GrossInUSD,Withhold,WithholdInBase,WithholdInUSD';

    public function testSamePaymentFromBothIbkrExportsIsCountedOnce(): void
    {
        $result = $this->import([
            'aktywnosc.csv' => self::activity([
                '"USD","AAPL","1","20250213;202000","82.00","Dividends","1"',
                '"USD","AAPL","1","20250213;202000","-12.30","Withholding Tax","2"',
            ]),
            'detail.csv' => self::detail([
                'DividendDetail,Data,Summary,USD,AAPL,1,US,20250213,20250207,100,,,82.00,75.20,82.00,-12.30,-11.28,-12.30,',
            ]),
        ]);

        self::assertCount(1, $result->dividends);
    }

    public function testTheRicherRecordWithACountryWins(): void
    {
        $result = $this->import([
            'aktywnosc.csv' => self::activity(['"USD","VUSD","1","20250402;202000","56.75","Dividends","1"']),
            'detail.csv' => self::detail([
                'DividendDetail,Data,Summary,USD,VUSD,2,IE,20250402,20250320,250,,,56.75,52.05,56.75,0,0,0,',
            ]),
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('IE', $result->dividends[0]->countryCode);
        self::assertStringContainsString('Dividend Detail', $result->dividends[0]->source);
    }

    public function testMergeOrderDoesNotMatter(): void
    {
        $result = $this->import([
            'detail.csv' => self::detail([
                'DividendDetail,Data,Summary,USD,VUSD,2,IE,20250402,20250320,250,,,56.75,52.05,56.75,0,0,0,',
            ]),
            'aktywnosc.csv' => self::activity(['"USD","VUSD","1","20250402;202000","56.75","Dividends","1"']),
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('IE', $result->dividends[0]->countryCode);
    }

    public function testTheMergeIsReportedSoTheUserCanCheckIt(): void
    {
        $result = $this->import([
            'aktywnosc.csv' => self::activity(['"USD","VUSD","1","20250402;202000","56.75","Dividends","1"']),
            'detail.csv' => self::detail([
                'DividendDetail,Data,Summary,USD,VUSD,2,IE,20250402,20250320,250,,,56.75,52.05,56.75,0,0,0,',
            ]),
        ]);

        $warnings = mb_strtolower(implode(' ', $result->warnings()));
        self::assertStringContainsString('scalono', $warnings);
        self::assertStringContainsString('vusd', $warnings);
    }

    public function testRecordsDifferingInAmountAreNotMerged(): void
    {
        $result = $this->import([
            'aktywnosc.csv' => self::activity(['"USD","AAPL","1","20250213;202000","82.00","Dividends","1"']),
            'detail.csv' => self::detail([
                'DividendDetail,Data,Summary,USD,AAPL,1,US,20250213,20250207,100,,,90.00,75.20,90.00,0,0,0,',
            ]),
        ]);

        self::assertCount(2, $result->dividends);
    }

    public function testRecordsDifferingInWithheldTaxAreNotMerged(): void
    {
        $result = $this->import([
            'aktywnosc.csv' => self::activity(['"USD","AAPL","1","20250213;202000","82.00","Dividends","1"']),
            'detail.csv' => self::detail([
                'DividendDetail,Data,Summary,USD,AAPL,1,US,20250213,20250207,100,,,82.00,75.20,82.00,-12.30,-11.28,-12.30,',
            ]),
        ]);

        self::assertCount(2, $result->dividends);
    }

    public function testRecordsDifferingInDateAreNotMerged(): void
    {
        $result = $this->import([
            'aktywnosc.csv' => self::activity(['"USD","AAPL","1","20250213;202000","82.00","Dividends","1"']),
            'detail.csv' => self::detail([
                'DividendDetail,Data,Summary,USD,AAPL,1,US,20250214,20250207,100,,,82.00,75.20,82.00,0,0,0,',
            ]),
        ]);

        self::assertCount(2, $result->dividends);
    }

    public function testTwoRecordsWithConflictingCountriesAreBothKept(): void
    {
        // Nothing here says which one is wrong, so silently dropping one would
        // hide a real data problem.
        $csv = static fn (string $country): string => "name,country,currency,date,amount,tax_paid\n"
            ."AAA,{$country},USD,2025-04-02,56.75,0\n";

        $result = $this->import([
            'a.csv' => $csv('US'),
            'b.csv' => $csv('IE'),
        ]);

        self::assertCount(2, $result->dividends);
        $countries = array_map(static fn ($d): string => $d->countryCode, $result->dividends);
        sort($countries);
        self::assertSame(['IE', 'US'], $countries);
    }

    public function testTwoBlankCountryRecordsForTheSamePaymentAreStillOneRecord(): void
    {
        $file = self::activity(['"USD","VUSD","1","20250402;202000","56.75","Dividends","1"']);

        $result = $this->import(['a.csv' => $file, 'b.csv' => $file]);

        self::assertCount(1, $result->dividends);
    }

    public function testThreeGenuinelyDifferentPaymentsAreAllKept(): void
    {
        $result = $this->import([
            'detail.csv' => self::detail([
                'DividendDetail,Data,Summary,USD,AAPL,1,US,20250213,20250207,100,,,82.00,75.20,82.00,-12.30,-11.28,-12.30,',
                'DividendDetail,Data,Summary,USD,VUSD,2,IE,20250402,20250320,250,,,56.75,52.05,56.75,0,0,0,',
                'DividendDetail,Data,Summary,CAD,BNS,3,CA,20250428,20250401,40,,,40.00,27.10,29.30,-10.00,-6.78,-7.33,',
            ]),
        ]);

        self::assertCount(3, $result->dividends);
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

        return (new CsvImportService(
            new FormatDetector(),
            [
                new IbkrTradesImporter(new FifoMatcher()),
                new IbkrActivityDividendsImporter(),
                new IbkrDividendDetailImporter(),
                new NormalizedPositionsImporter(),
                new NormalizedDividendsImporter(),
            ],
            new TaxRates(),
        ))->import($sources);
    }

    /**
     * @param list<string> $rows
     */
    private static function activity(array $rows): string
    {
        return self::ACTIVITY_HEADER."\n".implode("\n", $rows)."\n";
    }

    /**
     * @param list<string> $rows
     */
    private static function detail(array $rows): string
    {
        return "Account,Header,AccountNumber,AccountAlias,Name,BaseCurrency,\n"
            ."Account,Data,UXXXXXXXX,,Jan Przykladowy,EUR,\n"
            .self::DETAIL_HEADER."\n"
            .implode("\n", $rows)."\n";
    }
}

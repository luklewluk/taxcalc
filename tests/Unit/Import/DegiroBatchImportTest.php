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
use App\Import\ImportResult;
use App\Tax\TaxRates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Raw trades are pooled before FIFO runs, but only per broker: DEGIRO order IDs
 * and IBKR transaction IDs live in different namespaces, and an ISIN-keyed queue
 * must not be mixed with a ticker-keyed one.
 */
#[CoversClass(CsvImportService::class)]
#[CoversClass(DegiroTransactionsImporter::class)]
final class DegiroBatchImportTest extends TestCase
{
    private const string DEGIRO_HEADER =
        'Date,Time,Product,ISIN,Reference Exchange,Execution Venue,Quantity,Price,,Value,,'
        .'Transaction and/or third,,Total,,Order ID';

    private const string IBKR_HEADER =
        '"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"';

    public function testADegiroBuyFromOneYearMatchesASellFromAnother(): void
    {
        $result = $this->import([
            'degiro-2024.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,buy-1',
            ]),
            'degiro-2025.csv' => self::degiro([
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,sell-1',
            ]),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);

        $position = $result->positions[0];
        self::assertSame('2024-04-03', $position->buyDate->format('Y-m-d'));
        self::assertSame('2025-02-27', $position->sellDate->format('Y-m-d'));
        self::assertSame('1001.00', (string) $position->buyAmount->value());
        self::assertSame('1499.00', (string) $position->sellAmount->value());
        self::assertSame(2025, $position->taxYear());
    }

    public function testIbkrAndDegiroTradesAreBatchedAndMatchedIndependently(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,buy-1',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,sell-1',
            ]),
            'ibkr.csv' => self::ibkr([
                '"STK","BBB","20240101","5","10","-50.00","9001","USD"',
                '"STK","BBB","20250601","-5","20","100.00","9002","USD"',
            ]),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);

        $names = array_map(static fn ($p): string => $p->name, $result->positions);
        sort($names);
        self::assertSame(['ALFA CORP', 'BBB'], $names);

        // Each importer's own warning about the missing/inferred country shows up.
        $sources = implode(' ', array_map(static fn ($p): string => $p->source, $result->positions));
        self::assertStringContainsString('DEGIRO', $sources);
        self::assertStringContainsString('IBKR', $sources);
    }

    public function testAnIdenticalIdInTwoBrokersFilesIsNotACollision(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,SAME',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,SAME-2',
            ]),
            'ibkr.csv' => self::ibkr([
                '"STK","BBB","20240101","5","10","-50.00","SAME","USD"',
                '"STK","BBB","20250601","-5","20","100.00","SAME-2","USD"',
            ]),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);
    }

    public function testTheSameDegiroFileUploadedTwiceChangesNothing(): void
    {
        $file = self::degiro([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,buy-1',
            '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,sell-1',
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

    public function testTheSameDegiroFileWithoutOrderIdsUploadedTwiceDoesNotDoubleTax(): void
    {
        $file = self::degiro([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,',
            '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,',
        ]);

        $once = $this->import(['a.csv' => $file]);
        $twice = $this->import(['a.csv' => $file, 'b.csv' => $file]);

        self::assertCount(1, $once->positions);
        self::assertCount(1, $twice->positions);
    }

    public function testTwoIdenticalNoIdFillsInOneFileRemainTwoWhenFileIsRepeated(): void
    {
        $file = self::degiro([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,5,100.00,USD,-500.00,USD,-1.00,USD,-501.00,USD,',
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,5,100.00,USD,-500.00,USD,-1.00,USD,-501.00,USD,',
            '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-5,150.00,USD,750.00,USD,-1.00,USD,749.00,USD,',
            '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-5,150.00,USD,750.00,USD,-1.00,USD,749.00,USD,',
        ]);

        self::assertCount(2, $this->import(['a.csv' => $file])->positions);
        self::assertCount(2, $this->import(['a.csv' => $file, 'b.csv' => $file])->positions);
    }

    /**
     * The file name is not the identity of an upload: a browser renames files,
     * users save the same export twice, and two uploads can arrive with the same
     * name. Occurrence has to be counted inside the file that was read.
     */
    public function testTwoIdenticalNoIdFillsSurviveWhenTheSameNameIsUploadedTwice(): void
    {
        $file = self::degiro([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,5,100.00,USD,-500.00,USD,-1.00,USD,-501.00,USD,',
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,5,100.00,USD,-500.00,USD,-1.00,USD,-501.00,USD,',
            '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-5,150.00,USD,750.00,USD,-1.00,USD,749.00,USD,',
            '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-5,150.00,USD,750.00,USD,-1.00,USD,749.00,USD,',
        ]);

        $result = self::service()->import([
            new CsvSource('Transactions.csv', $file),
            new CsvSource('Transactions.csv', $file),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);
    }

    public function testANoIdFileUploadedTwiceUnderTheSameNameDoesNotDoubleTax(): void
    {
        $file = self::degiro([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,',
            '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,',
        ]);

        $result = self::service()->import([
            new CsvSource('Transactions.csv', $file),
            new CsvSource('Transactions.csv', $file),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertSame('1001.00', (string) $result->positions[0]->buyAmount->value());
    }

    public function testANoIdFileReuploadedUnderAnotherNameStillDeduplicates(): void
    {
        $file = self::degiro([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,',
            '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,',
        ]);

        $result = $this->import(['Transactions.csv' => $file, 'Transactions (1).csv' => $file]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
    }

    /**
     * The realistic overlap for a file without Order IDs: last year's statement
     * and a longer one that repeats it. The repeated buy must not open a second
     * lot, which would both understate the matched cost and leave a phantom
     * holding behind.
     */
    public function testOverlappingNoIdStatementsDoNotOpenASecondLot(): void
    {
        $buy = '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,';

        $result = $this->import([
            'degiro-2024.csv' => self::degiro([$buy]),
            'degiro-all.csv' => self::degiro([
                $buy,
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,',
            ]),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertSame('10', (string) $result->positions[0]->quantity);
        self::assertSame('1001.00', (string) $result->positions[0]->buyAmount->value());
    }

    public function testTwoIdenticalNoIdFillsKeepDistinctLineageSoNeitherIsDroppedAsADuplicate(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,5,100.00,USD,-500.00,USD,-1.00,USD,-501.00,USD,',
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,5,100.00,USD,-500.00,USD,-1.00,USD,-501.00,USD,',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,',
            ]),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);

        $fingerprints = array_map(static fn ($p): string => $p->fingerprint(), $result->positions);
        self::assertSame($fingerprints, array_unique($fingerprints));
    }

    public function testSameDayBuyAndSellUseDegiroTimeRegardlessOfUploadOrder(): void
    {
        $buy = self::degiro([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,buy-1',
        ]);
        $sell = self::degiro([
            '03-04-2024,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,sell-1',
        ]);

        $result = $this->import(['sell.csv' => $sell, 'buy.csv' => $buy]);

        self::assertCount(1, $result->positions);
        self::assertStringNotContainsString('nie ma pokrycia', implode(' ', $result->warnings()));
    }

    public function testDifferentBuyCostsAtTheSameDegiroMinuteAreFatalRegardlessOfFileOrder(): void
    {
        $cheap = self::degiro([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,1,100.00,USD,-100.00,USD,0.00,USD,-100.00,USD,buy-cheap',
        ]);
        $expensive = self::degiro([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,1,200.00,USD,-200.00,USD,0.00,USD,-200.00,USD,buy-expensive',
        ]);
        $sell = self::degiro([
            '04-04-2024,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-1,300.00,USD,300.00,USD,0.00,USD,300.00,USD,sell-1',
        ]);

        foreach ([[$cheap, $expensive, $sell], [$expensive, $cheap, $sell]] as $files) {
            $result = $this->import(['a.csv' => $files[0], 'b.csv' => $files[1], 's.csv' => $files[2]]);
            self::assertSame([], $result->positions);
            self::assertNotEmpty($result->errors());
            self::assertMatchesRegularExpression('/minut|kolejno/iu', implode(' ', $result->errors()));
        }
    }

    public function testOverlappingDegiroStatementsDoNotOpenASecondLot(): void
    {
        $result = $this->import([
            'degiro-2024.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,buy-1',
            ]),
            'degiro-all.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,buy-1',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,sell-1',
            ]),
        ]);

        self::assertCount(1, $result->positions);
        self::assertSame('10', (string) $result->positions[0]->quantity);
        self::assertSame('1001.00', (string) $result->positions[0]->buyAmount->value());
    }

    /**
     * DEGIRO fills one order in as many rows as the exchange needed. Those rows
     * share an Order ID and are all real trades - refusing them as a conflict,
     * or collapsing them into one, would both be wrong.
     */
    public function testPartialFillsSharingAnOrderIdAreAllKept(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,6,100.00,USD,-600.00,USD,-1.00,USD,-601.00,USD,buy-1',
                '03-04-2024,09:16,ALFA CORP,US000ALFA001,NDQ,XNAS,4,100.00,USD,-400.00,USD,-1.00,USD,-401.00,USD,buy-1',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,sell-1',
            ]),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);

        $cost = array_map(static fn ($p): string => (string) $p->buyAmount->value(), $result->positions);
        sort($cost);
        self::assertSame(['401.00', '601.00'], $cost);

        // The sell is prorated over the two lots and the slices add back up to
        // 1499.00 exactly: 6/10 of it, then the remaining balance.
        $proceeds = array_map(static fn ($p): string => (string) $p->sellAmount->value(), $result->positions);
        sort($proceeds);
        self::assertSame(['599.60', '899.40'], $proceeds);
    }

    /**
     * Two executions of one order that happen to be identical are still two
     * trades. Collapsing them would halve the taxable gain.
     */
    public function testTwoIdenticalFillsOfOneOrderAreBothKeptEvenWhenTheFileIsUploadedTwice(): void
    {
        $file = self::degiro([
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,5,100.00,USD,-500.00,USD,-1.00,USD,-501.00,USD,buy-1',
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,5,100.00,USD,-500.00,USD,-1.00,USD,-501.00,USD,buy-1',
            '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-5,150.00,USD,750.00,USD,-1.00,USD,749.00,USD,sell-1',
            '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-5,150.00,USD,750.00,USD,-1.00,USD,749.00,USD,sell-1',
        ]);

        $once = $this->import(['a.csv' => $file]);
        $twice = $this->import(['a.csv' => $file, 'b.csv' => $file]);

        self::assertSame([], $once->errors());
        self::assertCount(2, $once->positions);
        self::assertCount(2, $twice->positions);
    }

    public function testAnOrderIdReusedForAnotherInstrumentAbortsTheImport(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,SAME',
                '03-04-2024,09:15,BETA ETF,IE000BETA002,EAM,XAMS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,SAME',
            ]),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('SAME', implode(' ', $result->errors()));
        self::assertStringContainsString('Order ID', implode(' ', $result->errors()));
    }

    /**
     * A good-till-cancelled order can be filled over several sessions, so fills
     * of one Order ID on different days are normal - refusing them would reject
     * a legitimate export.
     */
    public function testPartialFillsOfOneOrderOnDifferentDaysAreAllKept(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,6,100.00,USD,-600.00,USD,-1.00,USD,-601.00,USD,gtc-1',
                '04-04-2024,10:20,ALFA CORP,US000ALFA001,NDQ,XNAS,4,100.00,USD,-400.00,USD,-1.00,USD,-401.00,USD,gtc-1',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,sell-1',
            ]),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);

        $costs = array_map(static fn ($p): string => (string) $p->buyAmount->value(), $result->positions);
        sort($costs);
        self::assertSame(['401.00', '601.00'], $costs);
    }

    public function testAMultiDayOrderStillDeduplicatesWhenStatementsOverlap(): void
    {
        $rows = [
            '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,6,100.00,USD,-600.00,USD,-1.00,USD,-601.00,USD,gtc-1',
            '04-04-2024,10:20,ALFA CORP,US000ALFA001,NDQ,XNAS,4,100.00,USD,-400.00,USD,-1.00,USD,-401.00,USD,gtc-1',
        ];

        $result = $this->import([
            'degiro-a.csv' => self::degiro($rows),
            'degiro-b.csv' => self::degiro([
                ...$rows,
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,sell-1',
            ]),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);
    }

    public function testOneOrderIdUsedForBothABuyAndASellAbortsTheImport(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,SAME',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,SAME',
            ]),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('SAME', implode(' ', $result->errors()));
    }

    public function testOneOrderIdUsedInTwoCurrenciesAbortsTheImport(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,6,100.00,USD,-600.00,USD,-1.00,USD,-601.00,USD,SAME',
                '03-04-2024,09:16,ALFA CORP,US000ALFA001,EAM,XAMS,4,100.00,EUR,-400.00,EUR,-1.00,EUR,-401.00,EUR,SAME',
            ]),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
    }

    /**
     * The same paper bought in one currency and sold in another is one FIFO
     * queue - keying the queue on the currency would leave the sale uncovered and
     * quietly drop the gain from the return. The closed-position record cannot
     * hold two currencies, so this is refused with an explanation instead.
     */
    public function testBuyingInOneCurrencyAndSellingInAnotherIsRefusedNotSilentlyDropped(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,buy-1',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,EAM,XAMS,-10,150.00,EUR,1500.00,EUR,-1.00,EUR,1499.00,EUR,sell-1',
            ]),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());

        $errors = implode(' ', $result->errors());
        self::assertStringContainsString('US000ALFA001', $errors);
        self::assertStringContainsString('USD', $errors);
        self::assertStringContainsString('EUR', $errors);
        self::assertMatchesRegularExpression('/walu/u', $errors);
    }

    public function testTheSameIsinTradedInTwoCurrenciesIndependentlyStillSettles(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,b1',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,s1',
                '04-04-2024,09:15,ALFA CORP,US000ALFA001,EAM,XAMS,5,100.00,EUR,-500.00,EUR,-1.00,EUR,-501.00,EUR,b2',
                '28-02-2025,14:30,ALFA CORP,US000ALFA001,EAM,XAMS,-5,150.00,EUR,750.00,EUR,-1.00,EUR,749.00,EUR,s2',
            ]),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->positions);

        $currencies = array_map(static fn ($p): string => $p->currency, $result->positions);
        sort($currencies);
        self::assertSame(['EUR', 'USD'], $currencies);
    }

    /**
     * A sale with no matching purchase means the cost basis is missing, so the
     * gain would be overstated for that position and the rest of the return
     * would look complete. Nothing may survive that.
     */
    public function testAnUncoveredSellEmptiesTheWholeBatchEvenWhenOtherPositionsAreFine(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,b1',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,s1',
                '27-02-2025,15:00,BETA ETF,IE000BETA002,EAM,XAMS,-4,150.00,EUR,600.00,EUR,-1.00,EUR,599.00,EUR,s2',
            ]),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('nie ma pokrycia', implode(' ', $result->errors()));
    }

    public function testACorporateActionRowEmptiesTheWholeBatchEvenWhenOtherPositionsAreFine(): void
    {
        $result = $this->import([
            'degiro.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,b1',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,s1',
                '01-07-2025,00:00,BETA ETF,IE000BETA002,EAM,XAMS,20,0.00,EUR,0.00,EUR,0.00,EUR,0.00,EUR,split-1',
            ]),
        ]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('korporacyjn', implode(' ', $result->errors()));
    }

    public function testAnIbkrUncoveredSellAlsoEmptiesTheBatch(): void
    {
        $result = $this->import([
            'ibkr.csv' => self::ibkr([
                '"STK","AAA","20240101","1","10","-10.00","1","USD"',
                '"STK","AAA","20240601","-1","15","15.00","2","USD"',
                '"STK","BBB","20240601","-5","15","75.00","3","USD"',
            ]),
        ]);

        self::assertSame([], $result->positions);
        self::assertStringContainsString('nie ma pokrycia', implode(' ', $result->errors()));
    }

    public function testAnIbkrConflictIsStillFatalWithTheIbkrColumnNamed(): void
    {
        $result = $this->import([
            'ibkr.csv' => self::ibkr([
                '"STK","AAA","20240101","1","10","-10.00","SAME","USD"',
                '"STK","AAA","20240101","1","20","-20.00","SAME","USD"',
            ]),
        ]);

        self::assertSame([], $result->positions);
        self::assertStringContainsString('TransactionID', implode(' ', $result->errors()));
    }

    public function testDegiroTradesAndDegiroDividendsCanBeUploadedTogether(): void
    {
        $result = $this->import([
            'degiro-transakcje.csv' => self::degiro([
                '03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00,USD,-1000.00,USD,-1.00,USD,-1001.00,USD,buy-1',
                '27-02-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,150.00,USD,1500.00,USD,-1.00,USD,1499.00,USD,sell-1',
            ]),
            'degiro-rachunek.csv' => "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n"
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,\n"
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-0.38,USD,499.62,\n",
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->positions);
        self::assertCount(1, $result->dividends);
    }

    public function testDividendAndItsTaxCanComeFromTwoOverlappingAccountFiles(): void
    {
        $header = "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n";
        $result = $this->import([
            'account-dividend.csv' => $header
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,\n",
            'account-tax.csv' => $header
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-1.50,USD,498.50,\n",
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('1.50', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testOrphanDegiroTaxMakesTheWholeMixedDividendBatchFailClosed(): void
    {
        $header = "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n";
        $result = $this->import([
            'tax-only.csv' => $header
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-1.50,USD,498.50,\n",
            'valid-dividend.csv' => $header
                ."20-05-2025,00:00,20-05-2025,BETA ETF,IE000BETA002,Dividend,,EUR,7.00,EUR,7.00,\n",
        ]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
        self::assertMatchesRegularExpression('/nie pasuje|brak.*dywidend/iu', implode(' ', $result->errors()));
    }

    /**
     * The account statement also lists the buys and sells, but the transaction
     * export is the canonical source for those - importing both would double
     * every position.
     */
    public function testTradeRowsInTheAccountStatementAreNotImportedAsTrades(): void
    {
        $result = $this->import([
            'degiro-rachunek.csv' => "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n"
                ."03-04-2024,09:15,03-04-2024,ALFA CORP,US000ALFA001,\"Buy 10 ALFA CORP@100.00 USD\",,USD,-1000.00,USD,500.00,buy-1\n"
                ."27-02-2025,14:30,27-02-2025,ALFA CORP,US000ALFA001,\"Sell 10 ALFA CORP@150.00 USD\",,USD,1500.00,USD,2000.00,sell-1\n",
        ]);

        self::assertSame([], $result->positions);
        self::assertSame([], $result->dividends);
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
    private static function degiro(array $rows): string
    {
        return self::DEGIRO_HEADER."\n".implode("\n", $rows)."\n";
    }

    /**
     * @param list<string> $rows
     */
    private static function ibkr(array $rows): string
    {
        return self::IBKR_HEADER."\n".implode("\n", $rows)."\n";
    }

    private static function service(): CsvImportService
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
        );
    }
}

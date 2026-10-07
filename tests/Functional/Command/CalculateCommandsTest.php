<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The CLI must keep working exactly as before the web UI existed, including the
 * command names and the normalized CSV formats.
 */
final class CalculateCommandsTest extends KernelTestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testCalculateFromFileStillReadsTheOriginalPositionsFormat(): void
    {
        $path = $this->file("name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n"
            ."Apple,US,USD,2024-05-04,100.00,2024-12-16,150.00\n");

        $tester = $this->invokeCommand('app:calculate-from-file', ['filepath' => $path]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $output = $tester->getDisplay();
        self::assertStringContainsString('Apple', $output);
        // USD is fixed at 4.0 in tests: cost 400, revenue 600, income 200, tax 38.
        self::assertStringContainsString('400.00', $output);
        self::assertStringContainsString('600.00', $output);
        self::assertStringContainsString('38.00', $output);
    }

    public function testCalculateFromFileReportsSemanticPitValuesNotFieldNumbers(): void
    {
        $path = $this->file("name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n"
            ."Apple,US,USD,2024-05-04,100.00,2024-12-16,150.00\n");

        $output = $this->invokeCommand('app:calculate-from-file', ['filepath' => $path])->getDisplay();

        self::assertStringContainsString('PIT-38', $output);
        self::assertStringContainsString('Przychód', $output);
        self::assertStringContainsString('Koszty uzyskania przychodu', $output);
        self::assertStringContainsString('PIT/ZG', $output);
        // Field numbers change between years, so they must not be printed.
        self::assertStringNotContainsString('C.22', $output);
        self::assertStringNotContainsString('G.45', $output);
    }

    public function testRawIbkrTradesCannotBeCalculatedInCliUntilCountryIsCompleted(): void
    {
        $path = $this->file('"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"'."\n"
            .'"STK","AAA","20240101","1","10","-10.00","1","USD"'."\n"
            .'"STK","AAA","20240601","-1","15","15.00","2","USD"'."\n");

        $tester = $this->invokeCommand('app:calculate-from-file', ['filepath' => $path]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/kraj/iu', $tester->getDisplay());
    }

    /**
     * Unlike the flat IBKR export, DEGIRO carries an ISIN, so the country can be
     * proposed and the CLI can finish - but it says out loud that the country was
     * inferred, because it is the user's job to confirm it.
     */
    public function testDegiroTransactionsAreCalculatedInCliWithAnInferredCountryAndAWarning(): void
    {
        $path = $this->file(
            'Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,'
            ."Transaction and/or third party costs,,Total,,Order ID\n"
            ."03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,10.0000,USD,-100.00,USD,-100.00,USD,,0.00,USD,-100.00,USD,aaa-1\n"
            ."27-02-2025,15:41,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,15.0000,USD,150.00,USD,150.00,USD,,0.00,USD,150.00,USD,aaa-2\n",
        );

        $tester = $this->invokeCommand('app:calculate-from-file', ['filepath' => $path, '--rok' => '2025']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $output = $tester->getDisplay();
        self::assertStringContainsString('ALFA CORP', $output);
        // USD is fixed at 4.0 in tests: cost 400, revenue 600, income 200, tax 38.
        self::assertStringContainsString('400.00', $output);
        self::assertStringContainsString('600.00', $output);
        self::assertStringContainsString('38.00', $output);
        // The country proposal now comes from the listing exchange, and the
        // command has to keep saying where it came from.
        self::assertMatchesRegularExpression('/giełd/iu', $output);
    }

    public function testTheActivityStatementSampleSettlesOptionsAndSaysWhichSideOpened(): void
    {
        $tester = $this->invokeCommand('app:calculate-from-file', [
            'filepath' => dirname(__DIR__, 3).'/examples/ibkr-activity-statement.csv',
            '--rok' => '2025',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $output = $tester->getDisplay();
        self::assertStringContainsString('Akcje, ETF-y i opcje', $output);
        self::assertStringContainsString('Opcja krótka', $output);
        self::assertStringContainsString('Opcja długa', $output);
        self::assertStringContainsString('dniu zamknięcia', $output);
    }

    public function testDegiroDividendsAreCalculatedFromTheAccountStatement(): void
    {
        $path = $this->file(
            "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n"
            ."13-02-2025,06:32,13-02-2025,ALFA CORP,US000ALFA001,Dividend,,USD,100.00,USD,100.00,\n"
            ."13-02-2025,06:32,13-02-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-15.00,USD,85.00,\n",
        );

        $tester = $this->invokeCommand('app:calculate-dividends-from-file', ['filepath' => $path, '--rok' => '2025']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $output = $tester->getDisplay();
        self::assertStringContainsString('ALFA CORP', $output);
        // Gross 100 USD at 4.0 = 400 PLN, Polish 19% = 76, withheld 15 USD = 60.
        self::assertStringContainsString('76.00', $output);
        self::assertStringContainsString('60.00', $output);
    }

    public function testDegiroAndIbkrFilesCanBeSettledInOneCliRun(): void
    {
        $degiro = $this->file(
            'Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,'
            ."Transaction and/or third party costs,,Total,,Order ID\n"
            ."03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,10.0000,USD,-100.00,USD,-100.00,USD,,0.00,USD,-100.00,USD,aaa-1\n"
            ."27-02-2025,15:41,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,15.0000,USD,150.00,USD,150.00,USD,,0.00,USD,150.00,USD,aaa-2\n",
        );
        $normalized = $this->file("name,country,currency,date,amount,tax_paid\n"
            ."BETA ETF,IE,USD,2025-08-13,100.00,0\n");

        $tester = $this->invokeCommand('app:calculate-from-file', [
            'filepath' => [$degiro, $normalized],
            '--rok' => '2025',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('ALFA CORP', $tester->getDisplay());
        self::assertStringContainsString('BETA ETF', $tester->getDisplay());
    }

    public function testCalculateDividendsFromFileStillReadsTheOriginalDividendsFormat(): void
    {
        $path = $this->file("name,country,currency,date,amount,tax_paid\n"
            ."Apple,US,USD,2024-08-13,100.00,15.00\n");

        $tester = $this->invokeCommand('app:calculate-dividends-from-file', ['filepath' => $path]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $output = $tester->getDisplay();
        self::assertStringContainsString('Apple', $output);
        self::assertStringContainsString('76.00', $output);
        self::assertStringContainsString('16.00', $output);
    }

    public function testYearCanBeSelectedExplicitly(): void
    {
        $path = $this->file("name,country,currency,date,amount,tax_paid\n"
            ."Apple,US,USD,2024-08-13,100.00,15.00\n"
            ."Apple,US,USD,2025-08-13,200.00,30.00\n");

        $output = $this->invokeCommand('app:calculate-dividends-from-file', [
            'filepath' => $path,
            '--rok' => '2024',
        ])->getDisplay();

        self::assertStringContainsString('2024', $output);
        self::assertStringContainsString('76.00', $output);
        self::assertStringNotContainsString('152.00', $output);
    }

    public function testMostRecentYearIsUsedWhenNoneIsGiven(): void
    {
        $path = $this->file("name,country,currency,date,amount,tax_paid\n"
            ."Apple,US,USD,2024-08-13,100.00,15.00\n"
            ."Apple,US,USD,2025-08-13,200.00,30.00\n");

        $output = $this->invokeCommand('app:calculate-dividends-from-file', ['filepath' => $path])->getDisplay();

        self::assertStringContainsString('2025', $output);
        self::assertStringContainsString('152.00', $output);
    }

    public function testMissingFileFailsCleanly(): void
    {
        $tester = $this->invokeCommand('app:calculate-from-file', ['filepath' => '/nie/ma/takiego/pliku.csv']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Nie można odczytać pliku', $tester->getDisplay());
    }

    public function testOneMissingFileAbortsOtherwiseValidMultiFileCalculation(): void
    {
        $valid = $this->file("name,country,currency,date,amount,tax_paid\n"
            ."Apple,US,USD,2024-08-13,100.00,15.00\n");
        $tester = $this->invokeCommand('app:calculate-from-file', [
            'filepath' => [$valid, '/nie/ma/takiego/pliku.csv'],
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringNotContainsString('Szacowany podatek', $tester->getDisplay());
    }

    public function testDirectoryInsteadOfFileFailsCleanly(): void
    {
        $tester = $this->invokeCommand('app:calculate-from-file', ['filepath' => sys_get_temp_dir()]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testUnrecognisedFormatFailsWithAnExplanation(): void
    {
        $path = $this->file("foo,bar\n1,2\n");

        $tester = $this->invokeCommand('app:calculate-from-file', ['filepath' => $path]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Nie rozpoznano formatu', $tester->getDisplay());
    }

    public function testMalformedRowMakesTheWholeCliCalculationFailClosed(): void
    {
        $path = $this->file("name,country,currency,date,amount,tax_paid\n"
            ."Bad,US,USD,nonsense,10.00,1.00\n"
            ."Good,US,USD,2024-08-13,100.00,15.00\n");

        $tester = $this->invokeCommand('app:calculate-dividends-from-file', ['filepath' => $path]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('nonsense', $tester->getDisplay());
        self::assertStringNotContainsString('Szacowany podatek', $tester->getDisplay());
    }

    public function testUnavailableNbpRateFailsInsteadOfReturningAPartialCliReport(): void
    {
        $path = $this->file("name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n"
            ."Good,US,USD,2024-01-01,100.00,2024-06-01,150.00\n"
            ."NoRate,JP,JPY,2024-01-01,1000.00,2024-06-01,1500.00\n");

        $tester = $this->invokeCommand('app:calculate-from-file', ['filepath' => $path]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('JPY', $tester->getDisplay());
        self::assertStringNotContainsString('Szacowany podatek', $tester->getDisplay());
    }

    public function testUnavailableDividendRateAlsoFailsClosedInCli(): void
    {
        $path = $this->file("name,country,currency,date,amount,tax_paid\n"
            ."NoRate,JP,JPY,2024-08-13,100.00,15.00\n");

        $tester = $this->invokeCommand('app:calculate-dividends-from-file', ['filepath' => $path]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('JPY', $tester->getDisplay());
        self::assertStringNotContainsString('Szacowany podatek', $tester->getDisplay());
    }

    public function testSeveralFilesCanBeCombinedInOneRun(): void
    {
        $positions = $this->file("name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n"
            ."Apple,US,USD,2024-05-04,100.00,2024-12-16,150.00\n");
        $dividends = $this->file("name,country,currency,date,amount,tax_paid\n"
            ."Apple,US,USD,2024-08-13,100.00,15.00\n");

        $tester = $this->invokeCommand('app:calculate-from-file', ['filepath' => [$positions, $dividends]]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('38.00', $tester->getDisplay());
        self::assertStringContainsString('16.00', $tester->getDisplay());
    }

    public function testEmptyResultIsNotAnErrorButIsExplained(): void
    {
        $path = $this->file("name,country,currency,date,amount,tax_paid\n"
            ."Apple,US,USD,2024-08-13,100.00,15.00\n");

        $tester = $this->invokeCommand('app:calculate-dividends-from-file', [
            'filepath' => $path,
            '--rok' => '2019',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('2019', $tester->getDisplay());
    }

    public function testDisclaimerIsPrinted(): void
    {
        $path = $this->file("name,country,currency,date,amount,tax_paid\n"
            ."Apple,US,USD,2024-08-13,100.00,15.00\n");

        $output = $this->invokeCommand('app:calculate-dividends-from-file', ['filepath' => $path])->getDisplay();

        // The console block wraps and gutters the text, so compare on a
        // whitespace- and gutter-normalised copy.
        $flattened = (string) preg_replace('/\s*!\s*|\s+/u', ' ', $output);

        self::assertStringContainsString('nie stanowi porady podatkowej', $flattened);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function invokeCommand(string $command, array $input): CommandTester
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $tester = new CommandTester($application->find($command));
        $tester->execute($input);

        return $tester;
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pitcli').'.csv';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}

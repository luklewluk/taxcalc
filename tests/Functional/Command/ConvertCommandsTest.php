<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The convert commands keep their original contract: IBKR export in, normalized
 * CSV out, with exactly the documented column names.
 */
final class ConvertCommandsTest extends KernelTestCase
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

    public function testTradesConversionProducesTheNormalizedPositionsFormat(): void
    {
        $input = $this->file('"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"'."\n"
            .'"STK","CSPX","20240403","3","507.5782","-1523.98","1","USD"'."\n"
            .'"STK","CSPX","20250227","-3","560.00","1680.00","2","USD"'."\n");
        $output = $this->path();

        $tester = $this->invokeCommand('app:convert-interactivebrokers', [
            'input_path' => $input,
            'output_path' => $output,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $csv = (string) file_get_contents($output);
        self::assertStringStartsWith(
            'name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount',
            $csv,
        );
        self::assertStringContainsString('CSPX,,USD,2024-04-03,1523.98,2025-02-27,1680.00', $csv);
    }

    public function testOptionPositionsAreLeftOutOfTheOwnFormatWithAWarning(): void
    {
        $output = $this->path();

        $tester = $this->invokeCommand('app:convert-interactivebrokers', [
            'input_path' => dirname(__DIR__, 3).'/examples/ibkr-activity-statement.csv',
            'output_path' => $output,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $csv = (string) file_get_contents($output);
        self::assertStringContainsString('ALFA CORP', $csv);
        self::assertStringNotContainsString('AAA 21MAR25 90 P', $csv);
        self::assertStringContainsString('Pominięto 3 pozycj', $tester->getDisplay());
    }

    public function testTradesConversionSaysTheCountryColumnIsEmptyOnlyWhenItIs(): void
    {
        $input = $this->file('"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"'."\n"
            .'"STK","CSPX","20240403","3","507.5782","-1523.98","1","USD"'."\n"
            .'"STK","CSPX","20250227","-3","560.00","1680.00","2","USD"'."\n");

        $tester = $this->invokeCommand('app:convert-interactivebrokers', [
            'input_path' => $input,
            'output_path' => $this->path(),
        ]);

        self::assertStringContainsString('country', $tester->getDisplay());
    }

    /**
     * A DEGIRO conversion fills the country in from the ISIN, so telling the user
     * the column is empty would be plainly wrong - it has to say "check it"
     * instead, or the one warning that matters gets ignored.
     */
    public function testDegiroConversionAsksToVerifyTheCountryRatherThanToFillItIn(): void
    {
        $input = $this->file(
            'Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,'
            ."Transaction and/or third party costs,,Total,,Order ID\n"
            ."03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,10.0000,USD,-100.00,USD,-100.00,USD,,0.00,USD,-100.00,USD,aaa-1\n"
            ."27-02-2025,15:41,ALFA CORP,US000ALFA001,NDQ,XNAS,-10,15.0000,USD,150.00,USD,150.00,USD,,0.00,USD,150.00,USD,aaa-2\n",
        );
        $output = $this->path();

        $tester = $this->invokeCommand('app:convert-interactivebrokers', [
            'input_path' => $input,
            'output_path' => $output,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('"ALFA CORP",US,USD,2024-04-03,100.00,2025-02-27,150.00', (string) file_get_contents($output));

        $flattened = (string) preg_replace('/\s*!\s*|\s+/u', ' ', $tester->getDisplay());
        self::assertStringNotContainsString('Kolumna "country" jest pusta', $flattened);
        self::assertMatchesRegularExpression('/sprawdź|zweryfikuj/iu', $flattened);
    }

    public function testDividendsConversionProducesTheNormalizedDividendsFormat(): void
    {
        $input = $this->file('"CurrencyPrimary","Symbol","Multiplier","Date/Time","Amount","Type","TransactionID"'."\n"
            .'"USD","VUSD","1","20250402;202000","56.75","Dividends","1"'."\n");
        $output = $this->path();

        $tester = $this->invokeCommand('app:convert-dividends-interactivebrokers', [
            'input_path' => $input,
            'output_path' => $output,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $csv = (string) file_get_contents($output);
        self::assertStringStartsWith('name,country,currency,date,amount,tax_paid', $csv);
        self::assertStringContainsString('VUSD,,USD,2025-04-02,56.75,0', $csv);
    }

    public function testDividendDetailExportKeepsTheCountry(): void
    {
        $input = $this->file(
            "Account,Header,AccountNumber,AccountAlias,Name,BaseCurrency,\n"
            ."DividendDetail,Header,DataDiscriminator,Currency,Symbol,Conid,Country,ReportDate,ExDate,Shares,RevenueComponent,QualifiedIndicator,Gross,GrossInBase,GrossInUSD,Withhold,WithholdInBase,WithholdInUSD\n"
            ."DividendDetail,Data,Summary,USD,AAA,1,US,20221230,20221215,3,,,1.35,1.26,1.35,-0.2,-0.19,-0.2,\n"
        );
        $output = $this->path();

        $tester = $this->invokeCommand('app:convert-dividends-interactivebrokers', [
            'input_path' => $input,
            'output_path' => $output,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('AAA,US,USD,2022-12-30,1.35,0.2', (string) file_get_contents($output));
    }

    public function testConvertedOutputIsSafeToOpenInASpreadsheet(): void
    {
        $input = $this->file('"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"'."\n"
            .'"STK","=cmd|calc","20240403","1","10","-10.00","1","USD"'."\n"
            .'"STK","=cmd|calc","20250227","-1","15","15.00","2","USD"'."\n");
        $output = $this->path();

        $this->invokeCommand('app:convert-interactivebrokers', ['input_path' => $input, 'output_path' => $output]);

        $csv = (string) file_get_contents($output);
        self::assertStringContainsString("'=cmd|calc", $csv);
        self::assertStringNotContainsString("\n=cmd|calc", $csv);
    }

    public function testMissingInputFileFailsCleanly(): void
    {
        $tester = $this->invokeCommand('app:convert-interactivebrokers', [
            'input_path' => '/nie/ma/pliku.csv',
            'output_path' => $this->path(),
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Nie można odczytać pliku', $tester->getDisplay());
    }

    public function testWrongFormatFailsWithAnExplanationInsteadOfAnEmptyFile(): void
    {
        $tester = $this->invokeCommand('app:convert-interactivebrokers', [
            'input_path' => $this->file("foo,bar\n1,2\n"),
            'output_path' => $this->path(),
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Nie rozpoznano formatu', $tester->getDisplay());
    }

    public function testUnwritableOutputPathFailsCleanly(): void
    {
        $tester = $this->invokeCommand('app:convert-interactivebrokers', [
            'input_path' => $this->file('"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"'."\n"
                .'"STK","AAA","20240101","1","10","-10.00","1","USD"'."\n"
                .'"STK","AAA","20240601","-1","15","15.00","2","USD"'."\n"),
            'output_path' => '/nie/ma/takiego/katalogu/out.csv',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('zapis', mb_strtolower($tester->getDisplay()));
    }

    /**
     * Converting a file that holds a sale with no purchase behind it would write
     * a normalized CSV quietly missing that position. The conversion stops
     * instead, and says which instrument is the problem.
     */
    public function testUnmatchedSellStopsTheConversionInsteadOfWritingAnIncompleteFile(): void
    {
        $input = $this->file('"AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"'."\n"
            .'"STK","AAA","20240601","-5","15","75.00","1","USD"'."\n"
            .'"STK","BBB","20240101","1","10","-10.00","2","USD"'."\n"
            .'"STK","BBB","20240601","-1","15","15.00","3","USD"'."\n");
        $output = $this->path();

        $tester = $this->invokeCommand('app:convert-interactivebrokers', [
            'input_path' => $input,
            'output_path' => $output,
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('AAA', $tester->getDisplay());
        self::assertFileDoesNotExist($output);
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
        $path = $this->path();
        file_put_contents($path, $content);

        return $path;
    }

    private function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pitconv').'.csv';
        $this->tempFiles[] = $path;

        return $path;
    }
}

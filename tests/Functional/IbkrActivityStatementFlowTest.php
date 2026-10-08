<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** The IBKR Activity Statement from upload to the workbench. */
final class IbkrActivityStatementFlowTest extends WebTestCase
{
    /** @var list<string> */
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

    public function testTheSampleStatementOpensTheWorkbenchWithTradesDividendsAndProposedCountries(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['statement.csv' => self::sample()]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.message--error')->count(), $crawler->filter('body')->text());

        $payload = $crawler->filter('form[data-workbench]')->form()->getPhpValues();
        // Five stock rows (one from an assignment) and six option rows.
        self::assertCount(11, $payload['trades']);

        $countries = [];
        $assets = [];
        foreach ($payload['trades'] as $trade) {
            $countries[$trade['symbol']] = $trade['country'];
            $assets[$trade['asset']] = ($assets[$trade['asset']] ?? 0) + 1;
            self::assertSame('IBKR', $trade['broker']);
        }
        self::assertSame('US', $countries['AAA']);
        self::assertSame('GB', $countries['BBB']);
        self::assertSame('US', $countries['AAA 21MAR25 90 P'], 'An option follows its exchange (CBOE).');
        self::assertSame(['STK' => 5, 'OPT' => 6], $assets);

        self::assertCount(1, $payload['dividends']);
        self::assertSame('US', $payload['dividends'][0]['country']);
        self::assertSame('0.75', $payload['dividends'][0]['tax_paid']);

        $fifo = $crawler->filter('#panel-fifo [data-fragment="fifo"]')->text();
        self::assertStringContainsString('ALFA CORP', $fifo);
        self::assertStringContainsString('opcja · krótka', $fifo);
        self::assertStringContainsString('opcja · długa', $fifo);

        self::assertStringContainsString('cenie wykonania', $crawler->filter('#panel-attention')->text());
    }

    public function testASaleWithoutItsPurchaseStillOpensTheWorkbenchWithOneWarningAndAResult(): void
    {
        // The 2025 statement alone: the sale of an instrument bought in 2024.
        $statement = "Statement,Header,Field Name,Field Value\n"
            ."Statement,Data,Title,Activity Statement\n"
            ."Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,C. Price,Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code\n"
            ."Trades,Data,Order,Stocks,USD,AAA,\"2025-02-27, 10:00:00\",-31,500,500,15500,-1,0,0,0,C\n"
            ."Trades,Data,Order,Stocks,USD,BBB,\"2025-03-03, 10:00:00\",1,50,50,-50,-1,51,0,0,O\n"
            ."Trades,Data,Order,Stocks,USD,BBB,\"2025-04-03, 10:00:00\",-1,55,55,55,-1,-51,3,0,C\n"
            ."Financial Instrument Information,Header,Asset Category,Symbol,Description,Conid,Security ID,Underlying,Listing Exch,Multiplier,Type,Code\n"
            ."Financial Instrument Information,Data,Stocks,AAA,ALFA CORP,1001,US000ALFA001,AAA,NASDAQ,1,COMMON,\n"
            ."Financial Instrument Information,Data,Stocks,BBB,BETA ETF,1002,IE000BETA002,BBB,LSEETF,1,ETF,\n";

        $client = static::createClient();
        $crawler = $this->import($client, ['2025.csv' => $statement]);

        self::assertCount(3, $crawler->filter('form[data-workbench]')->form()->getPhpValues()['trades']);
        self::assertSame(1, $crawler->filter('[data-diagnostic-code="fifo.unmatched_sell"]')->count());
        self::assertSame(1, substr_count($crawler->filter('#panel-attention')->text(), 'nie ma pokrycia'));
        self::assertStringContainsString('wcześniejszy rok', $crawler->filter('#panel-attention')->text());
        self::assertStringContainsString('BETA ETF', $crawler->filter('#panel-fifo [data-fragment="fifo"]')->text(), 'The covered sale settles.');
    }

    private static function sample(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/examples/ibkr-activity-statement.csv');
    }

    /** @param array<string, string> $files */
    private function import(KernelBrowser $client, array $files): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        return $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => $token],
            ['files' => $this->uploads($files)],
        );
    }

    /**
     * @param array<string, string> $files
     *
     * @return list<UploadedFile>
     */
    private function uploads(array $files): array
    {
        $uploads = [];
        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'pitas');
            self::assertIsString($path);
            file_put_contents($path, $content);
            $this->tempFiles[] = $path;
            $uploads[] = new UploadedFile($path, $name, 'text/csv', null, true);
        }

        return $uploads;
    }
}

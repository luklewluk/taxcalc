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
    private const string FLEX_AAA = <<<'CSV'
        "AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"
        "STK","AAA","20250203","10","100","-1001","1001","USD"
        "STK","AAA","20250915","-4","130","519","1002","USD"
        CSV;

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

        $fifo = $crawler->filter('#panel-fifo')->text();
        self::assertStringContainsString('ALFA CORP', $fifo);
        self::assertStringContainsString('opcja · krótka', $fifo);
        self::assertStringContainsString('opcja · długa', $fifo);

        self::assertStringContainsString('cenie wykonania', $crawler->filter('#panel-attention')->text());
    }

    public function testAStatementCannotJoinAWorkbenchThatAlreadySettlesTheSameTickerFromFlex(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['flex.csv' => self::FLEX_AAA]);
        self::assertCount(2, $crawler->filter('form[data-workbench]')->form()->getPhpValues()['trades']);

        $crawler = $client->request(
            'POST',
            '/kalkulator/import',
            $crawler->filter('form[data-workbench]')->form()->getPhpValues(),
            ['files' => $this->uploads(['statement.csv' => self::sample()])],
        );

        self::assertCount(2, $crawler->filter('form[data-workbench]')->form()->getPhpValues()['trades']);
        self::assertStringContainsString('cały upload odrzucono', mb_strtolower($crawler->filter('body')->text()));
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

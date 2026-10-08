<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Walks the whole user journey: upload -> review -> calculate -> download.
 */
final class CalculatorFlowTest extends WebTestCase
{
    /**
     * DEGIRO Transactions: two buys in 2024 closed by one sale in 2025. An
     * `XS` ISIN and no exchange columns, so nothing proposes a country, and no
     * transaction fee, so przychód is the settled cash.
     */
    private const string DEGIRO_TRADES = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        03-04-2024,09:15,CSPX,XS000CSPX001,,,3,507.5782,USD,-1523.98,USD,-1523.98,USD,,,,-1523.98,USD,t-1001
        13-06-2024,10:20,CSPX,XS000CSPX001,,,5,470.0000,USD,-2350.00,USD,-2350.00,USD,,,,-2350.00,USD,t-1002
        27-02-2025,15:41,CSPX,XS000CSPX001,,,-8,560.0000,USD,4480.00,USD,4480.00,USD,,,,4480.00,USD,t-1003
        CSV;

    /** DEGIRO Account statement: the ISIN prefix proposes US for AAA and IE for BBB. */
    private const string DEGIRO_DIVIDENDS = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend,,USD,100.00,USD,100.00,
        02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend Tax,,USD,-15.00,USD,85.00,
        02-07-2025,06:32,02-07-2025,BBB,IE000BBBB002,Dividend,,USD,50.00,USD,135.00,
        CSV;

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

    public function testCalculatorPageOffersYearSelectionAndUpload(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('input[type=file]')->count());
        self::assertGreaterThan(0, $crawler->filter('select[name="tax_year"] option')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="_token"]')->count());
    }

    public function testNoSampleFilesAreServed(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        self::assertSame(0, $crawler->filter('a[href^="/przyklady/"]')->count());

        $client->request('GET', '/przyklady/degiro-transakcje.csv');
        self::assertResponseStatusCodeSame(404);
    }

    public function testImportWithoutACsrfTokenIsRefused(): void
    {
        $client = static::createClient();
        $client->request('POST', '/kalkulator/import', ['tax_year' => '2025']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportShowsAnEditableReviewScreen(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::DEGIRO_TRADES]);

        self::assertResponseIsSuccessful();

        // Every trade is an editable row.
        self::assertSame(3, $crawler->filter('[data-trade-ledger] [data-trade] input[name^="trades"][name$="[name]"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="trades[0][total]"]')->count());
        self::assertSame('CSPX', $crawler->filter('input[name="trades[0][name]"]')->attr('value'));

        // One closed position per matched buy lot (two buys, one sell).
        $sale = $this->tradeRow($crawler, '2025-02-27');
        self::assertSame(2, $sale->filter('[data-trade-panel="details"] .trade-details__matches tbody tr')->count());

        // Source and detected format are shown.
        self::assertStringContainsString('trades.csv', $crawler->filter('body')->text());
        self::assertStringContainsString(
            'DEGIRO - transakcje giełdowe',
            $crawler->filter('body')->text(),
        );
    }

    public function testImportSaysTheTradesFormatCarriesNoCountryInTheAttentionPanel(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::DEGIRO_TRADES]);

        self::assertStringContainsString('Kraj', $crawler->filter('body')->text());
        self::assertSame(1, $crawler->filter('[data-diagnostic-code="country.missing_instrument"]')->count());
        self::assertStringContainsString('kraju uzyskania dochodu', $crawler->filter('#panel-attention')->text());
    }

    /**
     * The message strip carries one sentence and a link, never a list. Import
     * warnings and notices are deliberately not rendered in the web UI: a strip
     * that grows with every skipped row buries the only sentence that matters,
     * and everything that genuinely needs attention is a diagnostic.
     */
    public function testTheMessageStripHoldsOnlyTheAttentionSummary(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::DEGIRO_TRADES]);
        $strip = $crawler->filter('[data-fragment="messages"]');

        self::assertSame(1, $strip->filter('p.message')->count());
        self::assertStringContainsString('wymaga', $strip->text());
        self::assertStringContainsString('Zobacz pełną listę', $strip->text());
        self::assertSame(0, $strip->filter('.message--info, .message-group')->count());
    }

    public function testBuysFromAPreviousYearStillBackASaleInTheSelectedYear(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::DEGIRO_TRADES], '2025');
        $crawler = $this->submitReview($client, $crawler);

        // Buy legs are from 2024 while the sale is in 2025.
        $positions = $crawler->filter('#panel-fifo [data-fragment="fifo"] tbody tr');
        self::assertSame(2, $positions->count());
        self::assertSame('2024-04-03', trim($positions->eq(0)->filter('td')->eq(1)->text()));
        self::assertSame('2025-02-27', trim($positions->eq(0)->filter('td')->eq(7)->text()));
        self::assertSame('2024-06-13', trim($positions->eq(1)->filter('td')->eq(1)->text()));
        self::assertSame('2025-02-27', trim($positions->eq(1)->filter('td')->eq(7)->text()));
    }

    public function testUnsupportedFileIsReportedWithoutAServerError(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['junk.csv' => "foo,bar\n1,2\n"]);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.message--error')->count());
        self::assertStringContainsString('junk.csv', $crawler->filter('body')->text());
    }

    public function testRejectedUploadIsExplainedWithoutAServerError(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['evil.php' => "name,country\nAAA,US\n"]);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.message--error')->count());
    }

    public function testOneRejectedUploadDiscardsAllOtherwiseValidUploadedData(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, [
            'valid.csv' => self::DEGIRO_DIVIDENDS,
            'evil.php' => "name,country\nAAA,US\n",
        ]);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.message--error')->count());
        self::assertSame(0, $crawler->filter('[data-trade-ledger] [data-trade]')->count());
        self::assertSame(0, $crawler->filter('[data-editor-body="dividends"] > tr')->count());
    }

    public function testImportWithNoFilesAtAllIsExplained(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, []);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.message--error, .message--warning')->count());
    }

    public function testFullFlowProducesResultsWithBreakdownAndTotals(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, [
            'trades.csv' => self::DEGIRO_TRADES,
            'dividends.csv' => self::DEGIRO_DIVIDENDS,
        ], '2025');

        $crawler = $this->submitReview($client, $crawler);

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();

        self::assertStringContainsString('Przychód', $text);
        self::assertStringContainsString('Koszty uzyskania przychodu', $text);
        self::assertStringContainsString('Dochód', $text);
        // 19% stock tax and the dividend part are both shown.
        self::assertStringContainsString('19%', $text);
        self::assertStringContainsString('PIT/ZG', $text);
        // NBP rate and rate date columns for auditability.
        self::assertStringContainsString('Kurs NBP', $text);
        self::assertStringContainsString('nie stanowi porady podatkowej', $text);
    }

    public function testResultsRespectTheSelectedTaxYear(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DEGIRO_DIVIDENDS], '2024');
        $crawler = $this->submitReview($client, $crawler);

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();

        // Both dividends were paid in 2025, so a 2024 settlement excludes them.
        self::assertStringContainsString('2024', $text);
        self::assertMatchesRegularExpression('/pomini[eę]t|wykluczon|poza wybranym rokiem/iu', $text);
    }

    public function testCsvReportIsDownloadableAndNotPersisted(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DEGIRO_DIVIDENDS], '2025');

        $client->request('POST', '/kalkulator/raport.csv', self::withCountries($this->reviewPayload($crawler)));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString(
            'attachment;',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );

        $csv = $client->getResponse()->getContent();
        self::assertIsString($csv);
        self::assertStringContainsString('AAA', $csv);
        self::assertStringContainsString('nie stanowi porady podatkowej', $csv);

        self::assertSame([], glob(self::projectDir().'/var/*.csv') ?: []);
    }

    public function testPrintableHtmlReportIsAvailable(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DEGIRO_DIVIDENDS], '2025');

        $crawler = $client->request('POST', '/kalkulator/raport', self::withCountries($this->reviewPayload($crawler)));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('AAA', $crawler->filter('body')->text());
        self::assertGreaterThan(0, $crawler->filter('link[rel=stylesheet], style')->count());
    }

    public function testResultsEndpointRefusesRequestsWithoutACsrfToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/kalkulator/wynik', ['tax_year' => '2025']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidRowIsReportedOnTheReviewScreenInsteadOfCrashing(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DEGIRO_DIVIDENDS], '2025');

        $payload = self::withCountries($this->reviewPayload($crawler));
        $payload['dividends'][0]['date'] = 'zupelnie-zla-data';

        $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $client->getCrawler()->filter('.message--error')->count());
    }

    public function testUnavailableNbpRateReturnsToReviewAndNeverShowsAPartialResult(): void
    {
        $client = static::createClient();
        // The test rate provider knows no JPY.
        $csv = "Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID\n"
            ."02-01-2024,10:00,AAA,XS000AAAA001,,,1,1000.0000,JPY,-1000.00,JPY,-1000.00,JPY,,,,-1000.00,JPY,t-1\n"
            ."02-06-2025,10:00,AAA,XS000AAAA001,,,-1,1500.0000,JPY,1500.00,JPY,1500.00,JPY,,,,1500.00,JPY,t-2\n";
        $crawler = $this->import($client, ['trades.csv' => $csv], '2025');

        $crawler = $client->request('POST', '/kalkulator/wynik', self::withCountries($this->reviewPayload($crawler), 'JP'));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.message--error')->count());
        self::assertGreaterThan(0, $crawler->filter('form[data-role="review"]')->count());
        self::assertStringNotContainsString('Dwa warianty odliczenia', $crawler->filter('body')->text());
    }

    public function testUploadedDataIsNeverStoredInTheSession(): void
    {
        $client = static::createClient();
        $this->import($client, ['dividends.csv' => self::DEGIRO_DIVIDENDS], '2025');

        $session = $client->getRequest()->getSession();
        $serialized = json_encode($session->all());

        self::assertIsString($serialized);
        self::assertStringNotContainsString('AAA', $serialized);
        self::assertStringNotContainsString('100.00', $serialized);
    }

    public function testEveryRenderedValueIsHtmlEscaped(): void
    {
        $client = static::createClient();
        $csv = "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n"
            ."02-04-2025,06:32,02-04-2025,\"<script>alert(1)</script>\",US000AAAA001,Dividend,,USD,10.00,USD,10.00,\n"
            ."02-04-2025,06:32,02-04-2025,\"<script>alert(1)</script>\",US000AAAA001,Dividend Tax,,USD,-1.50,USD,8.50,\n";

        $crawler = $this->import($client, ['x.csv' => $csv], '2025');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);

        // The value survives intact as data - it is only ever escaped for output.
        self::assertSame(
            '<script>alert(1)</script>',
            $crawler->filter('input[name="dividends[0][name]"]')->attr('value'),
        );
    }

    /**
     * @param array<string, string> $files filename => content
     */
    private function import(KernelBrowser $client, array $files, string $year = '2025'): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $uploads = [];
        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'pitfunc');
            self::assertIsString($path);
            file_put_contents($path, $content);
            $this->tempFiles[] = $path;

            $uploads[] = new UploadedFile($path, $name, 'text/csv', null, true);
        }

        return $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => $year, '_token' => $token],
            ['files' => $uploads],
        );
    }

    private function submitReview(KernelBrowser $client, Crawler $crawler): Crawler
    {
        return $client->request('POST', '/kalkulator/wynik', self::withCountries($this->reviewPayload($crawler)));
    }

    /**
     * The trades fixture carries no usable country source, so the workbench
     * leaves it blank on purpose and the user has to pick one before calculating.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function withCountries(array $payload, string $country = 'US'): array
    {
        foreach (['trades', 'dividends'] as $group) {
            if (!isset($payload[$group]) || !is_array($payload[$group])) {
                continue;
            }

            foreach ($payload[$group] as $index => $row) {
                if (is_array($row) && '' === ($row['country'] ?? '')) {
                    $payload[$group][$index]['country'] = $country;
                }
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewPayload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-role="review"]')->form()->getPhpValues();
    }

    private function tradeRow(Crawler $crawler, string $date): Crawler
    {
        $trades = $this->reviewPayload($crawler)['trades'] ?? [];
        self::assertIsArray($trades);
        foreach ($trades as $trade) {
            if (is_array($trade) && ($trade['date'] ?? null) === $date) {
                $row = $crawler->filter('#row-'.$trade['id']);
                self::assertSame(1, $row->count());

                return $row;
            }
        }

        self::fail('Brak transakcji z dnia '.$date);
    }

    private static function projectDir(): string
    {
        return dirname(__DIR__, 2);
    }
}

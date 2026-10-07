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
    private const string IBKR_TRADES = <<<'CSV'
        "AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"
        "STK","CSPX","20240403","3","507.5782","-1523.98","1001","USD"
        "STK","CSPX","20240613","5","470.00","-2350.00","1002","USD"
        "STK","CSPX","20250227","-8","560.00","4480.00","1003","USD"
        CSV;

    private const string NORMALIZED_DIVIDENDS = <<<'CSV'
        name,country,currency,date,amount,tax_paid
        AAA,US,USD,2025-04-02,100.00,15.00
        BBB,IE,USD,2025-07-02,50.00,0
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

    public function testCalculatorPageLinksDownloadableSampleFiles(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        $links = $crawler->filter('a[href^="/przyklady/"]');
        self::assertGreaterThanOrEqual(2, $links->count());

        $client->request('GET', (string) $links->first()->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
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
        $crawler = $this->import($client, ['trades.csv' => self::IBKR_TRADES]);

        self::assertResponseIsSuccessful();

        // One closed position per matched buy lot (two buys, one sell).
        self::assertSame(2, $crawler->filter('input[name^="positions"][name$="[name]"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name="positions[0][buy_amount]"]')->count());

        self::assertSame('CSPX', $crawler->filter('input[name="positions[0][name]"]')->attr('value'));
        // Source and detected format are shown.
        self::assertStringContainsString('trades.csv', $crawler->filter('body')->text());
        self::assertStringContainsString(
            'IBKR - transakcje giełdowe',
            $crawler->filter('body')->text(),
        );
    }

    public function testImportSaysTheTradesFormatCarriesNoCountryInTheAttentionPanel(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::IBKR_TRADES]);

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
        $crawler = $this->import($client, ['trades.csv' => self::IBKR_TRADES]);
        $strip = $crawler->filter('[data-fragment="messages"]');

        self::assertSame(1, $strip->filter('p.message')->count());
        self::assertStringContainsString('wymaga', $strip->text());
        self::assertStringContainsString('Zobacz pełną listę', $strip->text());
        self::assertSame(0, $strip->filter('.message--info, .message-group')->count());
    }

    public function testBuysFromAPreviousYearStillBackASaleInTheSelectedYear(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::IBKR_TRADES], '2025');

        // Buy legs are from 2024 while the sale is in 2025.
        self::assertSame('2024-04-03', $crawler->filter('input[name="positions[0][buy_date]"]')->attr('value'));
        self::assertSame('2025-02-27', $crawler->filter('input[name="positions[0][sell_date]"]')->attr('value'));
        self::assertSame('2024-06-13', $crawler->filter('input[name="positions[1][buy_date]"]')->attr('value'));
        self::assertSame('2025-02-27', $crawler->filter('input[name="positions[1][sell_date]"]')->attr('value'));
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
            'valid.csv' => self::NORMALIZED_DIVIDENDS,
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
            'trades.csv' => self::IBKR_TRADES,
            'dividends.csv' => self::NORMALIZED_DIVIDENDS,
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
        $crawler = $this->import($client, ['dividends.csv' => self::NORMALIZED_DIVIDENDS], '2024');
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
        $crawler = $this->import($client, ['dividends.csv' => self::NORMALIZED_DIVIDENDS], '2025');

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
        $crawler = $this->import($client, ['dividends.csv' => self::NORMALIZED_DIVIDENDS], '2025');

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
        $crawler = $this->import($client, ['dividends.csv' => self::NORMALIZED_DIVIDENDS], '2025');

        $payload = self::withCountries($this->reviewPayload($crawler));
        $payload['dividends'][0]['date'] = 'zupelnie-zla-data';

        $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $client->getCrawler()->filter('.message--error')->count());
    }

    public function testUnavailableNbpRateReturnsToReviewAndNeverShowsAPartialResult(): void
    {
        $client = static::createClient();
        $csv = "name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n"
            ."AAA,JP,JPY,2024-01-01,1000.00,2025-06-01,1500.00\n";
        $crawler = $this->import($client, ['positions.csv' => $csv], '2025');

        $crawler = $client->request('POST', '/kalkulator/wynik', $this->reviewPayload($crawler));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.message--error')->count());
        self::assertGreaterThan(0, $crawler->filter('form[data-role="review"]')->count());
        self::assertStringNotContainsString('Dwa warianty odliczenia', $crawler->filter('body')->text());
    }

    public function testUploadedDataIsNeverStoredInTheSession(): void
    {
        $client = static::createClient();
        $this->import($client, ['dividends.csv' => self::NORMALIZED_DIVIDENDS], '2025');

        $session = $client->getRequest()->getSession();
        $serialized = json_encode($session->all());

        self::assertIsString($serialized);
        self::assertStringNotContainsString('AAA', $serialized);
        self::assertStringNotContainsString('100.00', $serialized);
    }

    public function testEveryRenderedValueIsHtmlEscaped(): void
    {
        $client = static::createClient();
        $csv = "name,country,currency,date,amount,tax_paid\n"
            ."\"<script>alert(1)</script>\",US,USD,2025-04-02,10.00,1.50\n";

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
     * The flat IBKR trade export carries no country, so the review screen leaves
     * it blank on purpose and the user has to pick one before calculating.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function withCountries(array $payload, string $country = 'US'): array
    {
        foreach (['positions', 'dividends'] as $group) {
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
        $form = $crawler->filter('form[data-role="review"]')->form();

        return $form->getPhpValues();
    }

    private static function projectDir(): string
    {
        return dirname(__DIR__, 2);
    }
}

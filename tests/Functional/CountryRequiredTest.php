<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Flat IBKR exports carry no country. Those rows must reach the review screen so
 * the user can fill them in, but must never reach a calculated result: the
 * country decides the withholding credit and the whole PIT/ZG attachment.
 */
final class CountryRequiredTest extends WebTestCase
{
    private const string IBKR_TRADES = <<<'CSV'
        "AssetClass","Symbol","TradeDate","Quantity","TradePrice","NetCash","TransactionID","CurrencyPrimary"
        "STK","CSPX","20240403","3","507.5782","-1523.98","1001","USD"
        "STK","CSPX","20250227","-3","560.00","1680.00","1002","USD"
        CSV;

    private const string DIVIDENDS = <<<'CSV'
        name,country,currency,date,amount,tax_paid
        AAA,US,USD,2025-04-02,100.00,30.00
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

    public function testBlankCountryReachesTheReviewScreen(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('input[name="positions[0][name]"]')->count());
        // The country select is rendered with nothing chosen.
        self::assertSame(
            1,
            $crawler->filter('select[name="positions[0][country]"] option[value=""][selected]')->count(),
        );
    }

    /**
     * The server refuses a blank country either way, but the browser has to say
     * so before the round trip: a select that is silently required sends the user
     * to a wall of red messages instead of to the field that needs them.
     *
     * Both tables, both attributes. `required` is what the browser enforces;
     * `aria-required` is what a screen reader announces, and older assistive
     * software does not derive one from the other.
     */
    public function testBothCountrySelectsAreMarkedRequiredForTheBrowserAndForAssistiveTech(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, [
            'trades.csv' => self::IBKR_TRADES,
            'dividends.csv' => self::DIVIDENDS,
        ]);

        $selects = $crawler->filter('select[name$="[country]"]');
        self::assertGreaterThan(0, $selects->count(), 'the review screen must offer a country select');

        $names = [];
        foreach ($selects as $node) {
            $name = $node->getAttribute('name');
            $names[] = $name;

            self::assertTrue($node->hasAttribute('required'), $name.' must be required');
            self::assertSame('true', $node->getAttribute('aria-required'), $name.' must be aria-required');
        }

        // Not just the position rows: the dividend rows carry the same duty.
        self::assertContains('positions[0][country]', $names);
        self::assertContains('dividends[0][country]', $names);
    }

    public function testBlankCountryCannotReachAResult(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $client->request('POST', '/kalkulator/wynik', $this->payload($crawler));

        self::assertResponseIsSuccessful();
        $text = $client->getCrawler()->filter('body')->text();

        self::assertGreaterThan(0, $client->getCrawler()->filter('.message--error')->count());
        self::assertMatchesRegularExpression('/kraj/iu', $text);
        self::assertStringNotContainsString('Szacowany podatek', $text);
    }

    public function testTheUserDoesNotLoseTheirRowsWhenValidationFails(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $crawler = $client->request('POST', '/kalkulator/wynik', $this->payload($crawler));

        // The offending row must come back so it can actually be corrected.
        self::assertSame('CSPX', $crawler->filter('input[name="positions[0][name]"]')->attr('value'));
        self::assertSame('1523.98', $crawler->filter('input[name="positions[0][buy_amount]"]')->attr('value'));
        self::assertSame('1', $crawler->filter('input[name="expected_positions"]')->attr('value'));
    }

    public function testFillingTheCountryInLetsTheCalculationThrough(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $payload = $this->payload($crawler);
        $payload['positions'][0]['country'] = 'IE';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Szacowany podatek', $text);
        self::assertStringContainsString('IE', $text);
    }

    public function testTamperedCountryIsRefused(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $payload = $this->payload($crawler);
        $payload['positions'][0]['country'] = 'USA';

        $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertGreaterThan(0, $client->getCrawler()->filter('.message--error')->count());
        self::assertStringNotContainsString('Szacowany podatek', $client->getCrawler()->filter('body')->text());
    }

    public function testReportDownloadIsAlsoGatedOnCountry(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $client->request('POST', '/kalkulator/raport.csv', $this->payload($crawler));

        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertGreaterThan(0, $client->getCrawler()->filter('.message--error')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-role="review"]')->form()->getPhpValues();
    }

    /**
     * @param array<string, string> $files filename => content, the trades export by default
     */
    private function import(KernelBrowser $client, array $files = ['trades.csv' => self::IBKR_TRADES]): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $uploads = [];
        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'pitcountry');
            self::assertIsString($path);
            file_put_contents($path, $content);
            $this->tempFiles[] = $path;

            $uploads[] = new UploadedFile($path, $name, 'text/csv', null, true);
        }

        return $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => $token],
            ['files' => $uploads],
        );
    }
}

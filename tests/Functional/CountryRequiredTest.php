<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Not every import can name a country: a DEGIRO trade with a non-country ISIN
 * prefix (`XS…`) and no exchange columns gets no proposal at all. Those rows
 * must reach the workbench so the user can fill them in, but must never reach a
 * calculated result: the country decides the withholding credit and the whole
 * PIT/ZG attachment.
 */
final class CountryRequiredTest extends WebTestCase
{
    /**
     * Neither the (blank) exchange nor the `XS` ISIN proposes a country,
     * whatever the "Kraj transakcji" setting. No fee: the przychód is the
     * settled cash.
     */
    private const string DEGIRO_TRADES = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        03-04-2024,09:15,CSPX,XS000CSPX001,,,3,507.5782,USD,-1523.98,USD,-1523.98,USD,,,,-1523.98,USD,t-1001
        27-02-2025,15:41,CSPX,XS000CSPX001,,,-3,560.0000,USD,1680.00,USD,1680.00,USD,,,,1680.00,USD,t-1002
        CSV;

    private const string DIVIDENDS = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend,,USD,100.00,USD,100.00,
        02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend Tax,,USD,-30.00,USD,70.00,
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
        self::assertSame(1, $crawler->filter('input[name="trades[0][name]"]')->count());
        self::assertSame(1, $crawler->filter('input[name="trades[1][name]"]')->count());
        // The country selects are rendered with nothing chosen.
        foreach (['trades[0][country]', 'trades[1][country]'] as $name) {
            $select = $crawler->filter(sprintf('select[name="%s"]', $name));
            self::assertSame(1, $select->count(), $name.' must be rendered');
            self::assertSame(0, $select->filter('option[selected]')->count(), $name.' must have nothing chosen');
        }
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
            'trades.csv' => self::DEGIRO_TRADES,
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

        // Not just the trade rows: the dividend rows carry the same duty.
        self::assertContains('trades[0][country]', $names);
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
        self::assertSame(1, $client->getCrawler()->filter('[data-diagnostic-code="country.missing_instrument"]')->count());
        self::assertStringNotContainsString('Szacowany podatek', $text);
    }

    public function testTheUserDoesNotLoseTheirRowsWhenValidationFails(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $crawler = $client->request('POST', '/kalkulator/wynik', $this->payload($crawler));

        // The offending rows must come back so they can actually be corrected.
        self::assertSame('CSPX', $crawler->filter('input[name="trades[0][name]"]')->attr('value'));
        self::assertSame('1523.98', $crawler->filter('input[name="trades[0][total]"]')->attr('value'));
        self::assertSame('1680.00', $crawler->filter('input[name="trades[1][total]"]')->attr('value'));
        self::assertSame('2', $crawler->filter('input[name="expected_trades"]')->attr('value'));
    }

    public function testFillingTheCountryInLetsTheCalculationThrough(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $payload = $this->payload($crawler);
        $payload['trades'][0]['country'] = 'IE';
        $payload['trades'][1]['country'] = 'IE';

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
        $payload['trades'][0]['country'] = 'USA';
        $payload['trades'][1]['country'] = 'USA';

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
    private function import(KernelBrowser $client, array $files = ['trades.csv' => self::DEGIRO_TRADES]): Crawler
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

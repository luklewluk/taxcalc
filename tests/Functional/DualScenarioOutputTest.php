<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Every surface that reports a tax figure has to show both readings of the
 * foreign-tax credit. A single unlabelled "estimated tax" would silently take a
 * side in a live legal dispute.
 *
 * The fixture is the canonical case: US dividend, 100 USD gross, 30 USD withheld.
 * At the fixed test rate of 4.0 that is 400 PLN gross and 120 PLN withheld, so
 * the Polish tax is 76.00, the conservative credit 60.00 (15% treaty cap) and
 * the NSA credit 76.00 (19%, the full Polish tax).
 */
final class DualScenarioOutputTest extends WebTestCase
{
    /**
     * The same payment as a DEGIRO account statement: the US country comes from
     * the ISIN prefix, the withholding is the separate "Dividend Tax" row.
     */
    private const string DEGIRO_ACCOUNT = <<<'CSV'
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

    public function testResultPageShowsBothTotalsAndNamesTheMethods(): void
    {
        $client = static::createClient();
        $crawler = $this->calculate($client);

        $text = $crawler->filter('body')->text();

        // Web presentation uses Polish decimal notation; CSV and CLI remain machine-oriented.
        $scenarioTable = $crawler->filter('details')->reduce(
            static fn (Crawler $node): bool => str_contains($node->filter('summary')->text(), 'KIS/NSA'),
        )->filter('table')->text();
        self::assertStringContainsString('60,00', $scenarioTable);
        self::assertStringContainsString('16,00', $scenarioTable);
        self::assertStringContainsString('76,00', $scenarioTable);
        self::assertStringContainsString('16,00', $scenarioTable);
        self::assertStringContainsString('0,00', $scenarioTable);

        self::assertMatchesRegularExpression('/zachowawcz/iu', $text);
        self::assertStringContainsString('NSA', $text);
        self::assertStringContainsString('II FSK 1171/22', $text);
        self::assertStringContainsString('II FSK 1302/22', $text);
    }

    public function testResultPageDoesNotPresentASingleUnlabelledTotal(): void
    {
        $client = static::createClient();
        $crawler = $this->calculate($client);
        $text = $crawler->filter('body')->text();

        // The dispute and the choice must both be stated.
        self::assertMatchesRegularExpression('/sporn/iu', $text);
        self::assertMatchesRegularExpression('/doradc|interpretacj/iu', $text);
    }

    public function testResultPageLinksTheLegalSources(): void
    {
        $client = static::createClient();
        $crawler = $this->calculate($client);

        $hrefs = $crawler->filter('a')->extract(['href']);
        $joined = implode(' ', $hrefs);

        self::assertStringContainsString('isap.sejm.gov.pl', $joined);
        self::assertStringContainsString('podatki.gov.pl', $joined);
        self::assertStringContainsString('orzeczenia.nsa.gov.pl', $joined);
    }

    public function testPrintableReportShowsBothScenarios(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $crawler = $client->request('POST', '/kalkulator/raport', $this->payload($crawler));
        $text = $crawler->filter('body')->text();

        self::assertMatchesRegularExpression('/zachowawcz/iu', $text);
        self::assertStringContainsString('NSA', $text);
        self::assertStringContainsString('16,00', $text);
        self::assertStringContainsString('0,00', $text);
    }

    public function testCsvReportCarriesBothScenariosAndTheirDescriptions(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $client->request('POST', '/kalkulator/raport.csv', $this->payload($crawler));
        $csv = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('DWA WARIANTY', $csv);
        self::assertStringContainsString('II FSK 1171/22', $csv);
        self::assertStringContainsString('II FSK 1302/22', $csv);
        // Both credit figures and both totals.
        self::assertStringContainsString('60.00', $csv);
        self::assertStringContainsString('76.00', $csv);
        self::assertStringContainsString('16.00', $csv);
        // Per-country columns for both readings.
        self::assertStringContainsString('Do odliczenia - wg NSA (PLN)', $csv);
        self::assertStringContainsString('Do zaplaty - zachowawczy (PLN)', $csv);
    }

    public function testCsvReportStaysFormulaSafeWithTheNewColumns(): void
    {
        $client = static::createClient();
        $csv = "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n"
            ."02-04-2025,06:32,02-04-2025,\"=cmd|' /C calc'!A0\",US000AAAA001,Dividend,,USD,100.00,USD,100.00,\n"
            ."02-04-2025,06:32,02-04-2025,\"=cmd|' /C calc'!A0\",US000AAAA001,Dividend Tax,,USD,-30.00,USD,70.00,\n";

        $crawler = $this->import($client, $csv);
        $client->request('POST', '/kalkulator/raport.csv', $this->payload($crawler));
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString("'=cmd", $body);
        self::assertStringNotContainsString(",=cmd", $body);
        self::assertStringNotContainsString("\n=cmd", $body);
    }

    private function calculate(KernelBrowser $client): Crawler
    {
        $crawler = $this->import($client);

        return $client->request('POST', '/kalkulator/wynik', $this->payload($crawler));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-role="review"]')->form()->getPhpValues();
    }

    private function import(KernelBrowser $client, ?string $csv = null): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $path = tempnam(sys_get_temp_dir(), 'pitdual');
        self::assertIsString($path);
        file_put_contents($path, $csv ?? self::DEGIRO_ACCOUNT);
        $this->tempFiles[] = $path;

        return $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => $token],
            ['files' => [new UploadedFile($path, 'dywidendy.csv', 'text/csv', null, true)]],
        );
    }
}

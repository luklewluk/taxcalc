<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The foreign-tax credit has two readings; the user picks one in Ustawienia and
 * every surface - summary, Dywidendy, print, CSV - shows that one only, named,
 * so the page never reads as a choice still to be made.
 *
 * The fixture is the canonical case: US dividend, 100 USD gross, 30 USD withheld.
 * At the fixed test rate of 4.0 that is 400 PLN gross and 120 PLN withheld, so
 * the Polish tax is 76.00, the conservative credit 60.00 (15% treaty cap, 16.00
 * due) and the NSA credit 76.00 (19%, nothing due).
 */
final class CreditMethodOutputTest extends WebTestCase
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

    public function testTheDefaultKisVariantIsTheOnlyOneOnTheWorkbench(): void
    {
        $client = static::createClient();
        $crawler = $this->calculate($client);

        self::assertSame('16,00 zł', self::fieldValue($crawler, '#panel-summary', 'do zapłaty'));
        self::assertSame('60,00 zł', self::fieldValue($crawler, '#panel-dividends', 'Do odliczenia'));

        $shown = $crawler->filter('#panel-summary')->text().' '.$crawler->filter('#panel-dividends')->text();
        self::assertStringContainsString('zachowawczy (KIS)', $shown);
        self::assertNoTraceOf('NSA', $shown);
    }

    public function testSwitchingToNsaReplacesTheFiguresEverywhereOnTheWorkbench(): void
    {
        $client = static::createClient();
        $crawler = $this->calculate($client, 'nsa');

        self::assertSame('0,00 zł', self::fieldValue($crawler, '#panel-summary', 'do zapłaty'));
        self::assertSame('76,00 zł', self::fieldValue($crawler, '#panel-dividends', 'Do odliczenia'));

        $shown = $crawler->filter('#panel-summary')->text().' '.$crawler->filter('#panel-dividends')->text();
        self::assertStringContainsString('wg NSA', $shown);
        self::assertNoTraceOf('KIS', $shown);
    }

    /**
     * The summary names the reading next to its figures and explains nothing
     * more; what the readings mean, and where they come from, is told where
     * the choice is made.
     */
    public function testTheReadingsAreExplainedInTheSettingsNotInTheSummary(): void
    {
        $client = static::createClient();
        $crawler = $this->calculate($client);

        self::assertSame(0, $crawler->filter('#panel-summary [data-role="credit-method"]')->count());
        self::assertStringNotContainsString('Krajowa Informacja Skarbowa', $crawler->filter('#panel-summary')->text());

        $settings = $crawler->filter('#panel-settings');
        self::assertStringContainsString('Krajowa Informacja Skarbowa', $settings->text());
        self::assertStringContainsString('II FSK 1171/22', $settings->text());
        $links = implode(' ', $settings->filter('a')->extract(['href']));
        self::assertStringContainsString('isap.sejm.gov.pl', $links);
        self::assertStringContainsString('orzeczenia.nsa.gov.pl', $links);
    }

    public function testPrintableReportShowsOnlyTheChosenVariant(): void
    {
        $client = static::createClient();

        $kis = $this->report($client, '/kalkulator/raport', null)->filter('body')->text();
        self::assertStringContainsString('zachowawczy (KIS)', $kis);
        self::assertStringContainsString('16,00', $kis);
        self::assertNoTraceOf('NSA', $kis);

        $nsa = $this->report($client, '/kalkulator/raport', 'nsa')->filter('body')->text();
        self::assertStringContainsString('wg NSA', $nsa);
        self::assertNoTraceOf('KIS', $nsa);
    }

    public function testCsvReportCarriesOnlyTheChosenVariant(): void
    {
        $client = static::createClient();

        $this->report($client, '/kalkulator/raport.csv', null);
        $kis = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('WARIANT ODLICZENIA', $kis);
        self::assertStringContainsString('Wariant zachowawczy', $kis);
        self::assertStringContainsString('60.00', $kis);
        self::assertStringContainsString('16.00', $kis);
        self::assertStringNotContainsString('NSA', $kis);
        self::assertStringNotContainsString('DWA WARIANTY', $kis);

        $this->report($client, '/kalkulator/raport.csv', 'nsa');
        $nsa = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Wariant wg orzecznictwa NSA', $nsa);
        self::assertStringContainsString('II FSK 1171/22', $nsa);
        self::assertStringNotContainsString('zachowawcz', $nsa);
    }

    public function testCsvReportStaysFormulaSafe(): void
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

    /**
     * Nothing that names the other reading, its figures or a comparison.
     */
    private static function assertNoTraceOf(string $other, string $text): void
    {
        self::assertStringNotContainsString($other, $text);
        self::assertStringNotContainsStringIgnoringCase('alternatywn', $text);
        self::assertStringNotContainsStringIgnoringCase('różnica', $text);
        self::assertStringNotContainsStringIgnoringCase('dwa warianty', $text);
    }

    /**
     * The value of the first `dt`/`dd` pair in `$panel` whose label contains `$label`.
     */
    private static function fieldValue(Crawler $crawler, string $panel, string $label): string
    {
        $pair = $crawler->filter($panel.' dl > div')->reduce(
            static fn (Crawler $node): bool => str_contains($node->filter('dt')->text(), $label),
        )->first();

        // The figure is the dd's own text; notes ride in nested spans.
        $dd = $pair->filter('dd');
        $value = $dd->text();
        foreach ($dd->filter('span')->each(static fn (Crawler $note): string => $note->text()) as $note) {
            $value = str_replace($note, '', $value);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function calculate(KernelBrowser $client, ?string $creditMethod = null): Crawler
    {
        $payload = $this->payload($this->import($client));
        if (null !== $creditMethod) {
            $payload['credit_method'] = $creditMethod;
        }

        return $client->request('POST', '/kalkulator/wynik', $payload);
    }

    private function report(KernelBrowser $client, string $uri, ?string $creditMethod): Crawler
    {
        $payload = $this->payload($this->import($client));
        if (null !== $creditMethod) {
            $payload['credit_method'] = $creditMethod;
        }

        return $client->request('POST', $uri, $payload);
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

        $path = tempnam(sys_get_temp_dir(), 'pitcredit');
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

<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The Opłaty and Dywidendy editors: a fee's category is picked from a list,
 * "Usuń" is a button that removes the row at once, and the notes explain the
 * correction flag.
 */
final class FeeAndDividendEditorsTest extends WebTestCase
{
    private const string ACCOUNT = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        03-02-2025,00:00,03-02-2025,,,DEGIRO Exchange Connection Fee 2025 (Xetra - XET),,EUR,-2.50,EUR,100.00,
        05-06-2025,00:00,05-06-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,10.00,
        CSV;

    private const array CATEGORIES = ['Połączenie z giełdą', 'Prowadzenie rachunku', 'Dane rynkowe', 'Przelewy i wypłaty', 'Inne'];

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

    public function testAFeesCategoryIsPickedFromAList(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $select = $crawler->filter('select[name="fees[0][category]"]');
        self::assertSame(self::CATEGORIES, $select->filter('option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertSame('Połączenie z giełdą', $select->filter('option[selected]')->attr('value'));

        $template = (string) $crawler->filter('template[data-row-template="fees"]')->html();
        self::assertStringContainsString('<select name="fees[__INDEX__][category]"', $template);
        self::assertStringContainsString('value="Inne" selected', $template);
    }

    /** A category from before the list existed keeps its value instead of being lost on the next post. */
    public function testACategoryOutsideTheListSurvivesTheRoundTrip(): void
    {
        $client = static::createClient();
        $payload = $this->payload($this->import($client));
        $payload['fees'][0]['category'] = 'Połączenie z giełdą DEGIRO';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        self::assertSame('Połączenie z giełdą DEGIRO', $crawler->filter('select[name="fees[0][category]"] option[selected]')->attr('value'));
    }

    public function testRemoveIsAButtonThatSubmitsAndLeavesATombstone(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        self::assertSame(0, $crawler->filter('[data-editor-body] input[type="checkbox"][name$="[remove]"]')->count());
        foreach (['fees' => 'panel-fees', 'dividends' => 'panel-dividends'] as $group => $panel) {
            $button = $crawler->filter('button[name="'.$group.'[0][remove]"]');
            self::assertSame(1, $button->count());
            self::assertSame('1', $button->attr('value'));
            self::assertNotNull($button->attr('formnovalidate'));
            self::assertNotNull($button->attr('data-row-remove'));
            self::assertStringEndsWith('/kalkulator/wynik#'.$panel, (string) $button->attr('formaction'));
        }

        $feeId = (string) $crawler->filter('input[name="fees[0][id]"]')->attr('value');
        $crawler = $client->submit($crawler->filter('button[name="fees[0][remove]"]')->form());

        self::assertSame(0, $crawler->filter('[data-editor-body="fees"] > tr')->count());
        self::assertSame(1, $crawler->filter('input[name="tombstones[]"][value="'.$feeId.'"]')->count());
        self::assertSame(1, $crawler->filter('[data-editor-body="dividends"] > tr')->count(), 'only the fee goes');
    }

    public function testTheNotesExplainTheCorrectionFlag(): void
    {
        $client = static::createClient();
        $text = $this->import($client)->filter('#panel-fees')->text();

        self::assertStringContainsString('Korekta / zwrot', $text);
        self::assertStringContainsString('zmniejsza koszty', $text);
        self::assertStringContainsString('podatek od transakcji', $text);
    }

    private function import(KernelBrowser $client): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $path = tempnam(sys_get_temp_dir(), 'piteditors');
        self::assertIsString($path);
        file_put_contents($path, self::ACCOUNT);
        $this->tempFiles[] = $path;

        $crawler = $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value')],
            ['files' => [new UploadedFile($path, 'konto.csv', 'text/csv', null, true)]],
        );
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return array<string, mixed> */
    private function payload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-workbench]')->form()->getPhpValues();
    }
}

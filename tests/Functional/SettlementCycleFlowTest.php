<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The settlement cycle is a setting: by default each leg takes the rate before
 * its trade date, as before; under a settlement cycle it takes the rate before
 * the day it settles, and the sale's settlement decides the tax year.
 */
final class SettlementCycleFlowTest extends WebTestCase
{
    /** DEGIRO, listed on NASDAQ: bought in March, sold on New Year's Eve. */
    private const string NEW_YEARS_EVE = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        03-03-2025,10:00,ALFA CORP,US000ALFA001,NDQ,XNAS,2,100.0000,USD,-200.00,USD,-200.00,USD,,,,-200.00,USD,t-7001
        31-12-2025,15:00,ALFA CORP,US000ALFA001,NDQ,XNAS,-2,150.0000,USD,300.00,USD,300.00,USD,,,,300.00,USD,t-7002
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

    public function testTheTradeDateIsTheDefaultAndMovesNothing(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $select = $crawler->filter('#panel-settings select[name="settlement_cycle"]');
        self::assertSame(1, $select->count());
        self::assertSame(0, $crawler->filter('[data-fragment] select[name="settlement_cycle"]')->count());
        self::assertSame('trade_date', $select->filter('option[selected]')->attr('value'));
        self::assertNotNull($select->attr('data-full-reload'));

        $pairs = $crawler->filter('#panel-fifo [data-fragment="fifo"]');
        self::assertSame(1, $pairs->filter('tbody tr')->count());
        self::assertStringNotContainsString('rozliczenie', $pairs->filter('tbody')->text());
    }

    public function testUnderTheMarketCycleTheNewYearsEveSaleSettlesAndCountsInJanuary(): void
    {
        $client = static::createClient();
        $payload = $this->payload($this->import($client));
        $payload['settlement_cycle'] = 'market';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        self::assertSame('market', $crawler->filter('select[name="settlement_cycle"] option[selected]')->attr('value'));
        self::assertStringContainsString('Brak zamkniętych pozycji', $crawler->filter('#panel-fifo [data-fragment="fifo"]')->text());

        $payload['tax_year'] = '2026';
        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $pairs = $crawler->filter('#panel-fifo [data-fragment="fifo"]');
        self::assertSame(1, $pairs->filter('tbody tr')->count());
        self::assertStringContainsString('rozliczenie 2025-03-04', $pairs->text());
        self::assertStringContainsString('rozliczenie 2026-01-02', $pairs->text());
        self::assertStringContainsString('dzień rozliczenia', $pairs->filter('[data-calc-notes]')->text());

        $client->request('POST', '/kalkulator/wynik', $payload, [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $json = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($json['ok']);
        self::assertStringContainsString('rozliczenie 2026-01-02', $json['fragments']['fifo']);
    }

    public function testTheCsvStatesTheCycleAndTheSettlementDates(): void
    {
        $client = static::createClient();
        $payload = $this->payload($this->import($client));
        $payload['settlement_cycle'] = 'market';
        $payload['tax_year'] = '2026';

        $client->request('POST', '/kalkulator/raport.csv', $payload);
        self::assertResponseIsSuccessful();
        $csv = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('"Cykl rozliczenia"', $csv);
        self::assertStringContainsString('"Data rozliczenia sprzedazy"', $csv);
        self::assertStringContainsString(',2025-03-04,2026-01-02', $csv);
    }

    private function import(KernelBrowser $client): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $path = tempnam(sys_get_temp_dir(), 'pitcycle');
        self::assertIsString($path);
        file_put_contents($path, self::NEW_YEARS_EVE);
        $this->tempFiles[] = $path;

        $crawler = $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value')],
            ['files' => [new UploadedFile($path, 'transakcje.csv', 'text/csv', null, true)]],
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

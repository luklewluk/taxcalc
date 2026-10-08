<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The Transakcje tab is a read-only ledger: stocks apart from options, one
 * group per FIFO queue, every field still posted, and every trade with what
 * FIFO made of it - for every year.
 */
final class TradeLedgerTest extends WebTestCase
{
    /**
     * DEGIRO: one purchase, sold half in 2024 and half in 2025. No transaction
     * fee, and no country - an `XS` ISIN and no exchange columns propose none.
     */
    private const string TWO_YEARS = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        02-01-2024,10:00,CSPX,XS000CSPX001,,,2,100.0000,USD,-200.00,USD,-200.00,USD,,,,-200.00,USD,t-5001
        03-06-2024,11:00,CSPX,XS000CSPX001,,,-1,150.0000,USD,150.00,USD,150.00,USD,,,,150.00,USD,t-5002
        03-03-2025,12:00,CSPX,XS000CSPX001,,,-1,170.0000,USD,170.00,USD,170.00,USD,,,,170.00,USD,t-5003
        CSV;

    /** DEGIRO: a CSPX sale with no purchase, next to an unrelated SPY purchase. */
    private const string ORPHAN = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        27-02-2025,15:41,CSPX,XS000CSPX001,,,-1,560.0000,USD,560.00,USD,560.00,USD,,,,560.00,USD,t-1002
        03-01-2025,10:00,SPY,XS000SPYY002,,,1,450.0000,USD,-450.00,USD,-450.00,USD,,,,-450.00,USD,t-3001
        CSV;

    /** Every name a trade row posted before the ledger existed - and none other. */
    private const string FIELD_NAME = '/^trades\[\d+]\[(id|exchange|broker|pool|symbol|name|country|asset|effect|date|time|side|quantity|currency|total|unit_price|price_currency|commission|autofx|external_id|source|remove)]$/';

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

    public function testStocksAndOptionsAreSeparateSectionsWithOneGroupPerQueue(): void
    {
        $client = static::createClient();
        $crawler = $this->importSample($client);

        $ledger = $crawler->filter('[data-trade-ledger]');
        self::assertSame(1, $ledger->count());
        self::assertSame(
            ['Akcje i ETF-y', 'Opcje'],
            $ledger->filter('[data-ledger-section] > h3')->each(static fn (Crawler $node): string => trim($node->text())),
        );
        self::assertSame(2, $ledger->filter('[data-ledger-section="stocks"] .instrument')->count());
        self::assertSame(3, $ledger->filter('[data-ledger-section="options"] .instrument')->count());
        self::assertStringContainsString('wystawiona', $ledger->filter('[data-ledger-section="options"]')->text());
        self::assertSame(count($this->payload($crawler)['trades']), $ledger->filter('[data-trade]')->count());
    }

    public function testWithoutOptionsThereIsNoOptionSection(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, self::TWO_YEARS);

        self::assertSame(1, $crawler->filter('[data-ledger-section="stocks"]')->count());
        self::assertSame(0, $crawler->filter('[data-ledger-section="options"]')->count());
    }

    public function testEveryFieldStillPostsWhileNoEditorIsOpen(): void
    {
        $client = static::createClient();
        $crawler = $this->importSample($client);

        self::assertSame(0, $crawler->filter('[data-trade-ledger] [data-trade-panel="edit"][open]')->count());

        $names = $crawler->filter('[data-trade-ledger] [data-trade] [name]')->each(
            static fn (Crawler $node): string => (string) $node->attr('name'),
        );
        self::assertNotSame([], $names);
        foreach ($names as $name) {
            self::assertMatchesRegularExpression(self::FIELD_NAME, $name, 'A trade row must not post a new field: max_input_vars.');
        }

        $trades = $this->payload($crawler)['trades'];
        self::assertSame(
            ['asset', 'autofx', 'broker', 'commission', 'country', 'currency', 'date', 'effect', 'external_id', 'id', 'name', 'pool', 'price_currency', 'quantity', 'side', 'source', 'symbol', 'time', 'total', 'unit_price'],
            array_values(array_diff(self::sortedKeys($trades[0]), ['exchange'])),
        );
    }

    public function testSaveSubmitsForRealAndComesBackToItsRow(): void
    {
        $client = static::createClient();
        $crawler = $this->importSample($client);

        $id = $this->payload($crawler)['trades'][0]['id'];
        $save = $crawler->filter('#row-'.$id.' [data-trade-save]');
        self::assertSame(1, $save->count());
        self::assertNotNull($save->attr('formnovalidate'));
        self::assertStringEndsWith('/kalkulator/wynik#row-'.$id, (string) $save->attr('formaction'));
    }

    public function testPostingTheLedgerBackChangesNoFigure(): void
    {
        $client = static::createClient();
        $crawler = $this->importSample($client);
        $summary = $crawler->filter('#panel-summary')->text();
        $positions = self::fifoRows($crawler);
        self::assertNotSame([], $positions);

        // The rows come back in ledger order - queue by queue, in time order.
        $crawler = $client->request('POST', '/kalkulator/wynik', $this->payload($crawler));

        self::assertSame($summary, $crawler->filter('#panel-summary')->text());
        self::assertSame($positions, self::fifoRows($crawler));
    }

    public function testASaleOfAnotherYearShowsItsLotAndTax(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, self::TWO_YEARS);
        $payload = $this->payload($crawler);
        foreach ($payload['trades'] as &$trade) {
            $trade['country'] = 'US';
        }
        unset($trade);
        $payload['tax_year'] = '2025';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $sale = $this->rowFor($crawler, '2024-06-03');
        $details = $sale->filter('[data-trade-panel="details"]')->text();

        self::assertStringContainsString('Zamyka', $details);
        self::assertStringContainsString('2024-01-02', $details);
        self::assertStringContainsString('400,00 zł', $details, 'Cost: 100 USD × 4.0.');
        self::assertStringContainsString('600,00 zł', $details, 'Przychód: 150 USD × 4.0.');
        self::assertStringContainsString('200,00 zł', $details);
        self::assertStringContainsString('38,00 zł', $details, '19% informacyjnie.');
        self::assertStringContainsString('2024', $sale->filter('.trade__summary')->text());

        $purchase = $this->rowFor($crawler, '2024-01-02')->filter('[data-trade-panel="details"]')->text();
        self::assertStringContainsString('Zamknięta przez', $purchase);
        self::assertStringContainsString('2025-03-03', $purchase);
    }

    public function testDetailsAreThereEvenWhileAMissingCountryBlocksTheResult(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, self::TWO_YEARS);

        self::assertSame(1, $crawler->filter('[data-diagnostic-code="country.missing_instrument"]')->count());
        self::assertStringContainsString('Zamyka', $this->rowFor($crawler, '2024-06-03')->filter('[data-trade-panel="details"]')->text());

        $header = $crawler->filter('[data-trade-ledger] .instrument__header');
        self::assertStringContainsString('brak kraju: 3', $header->text());
        $link = $header->filter('a[href^="#attention-"]');
        self::assertSame(1, $link->count());
        self::assertSame(1, $crawler->filter((string) $link->attr('href'))->count(), 'The header points at an existing attention item.');
    }

    public function testASaleWithoutAPurchaseIsExplainedAndLinkedToItsDetails(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, self::ORPHAN);
        $payload = $this->payload($crawler);
        foreach ($payload['trades'] as &$trade) {
            $trade['country'] = 'US';
        }
        unset($trade);

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $item = $crawler->filter('[data-diagnostic-code="fifo.unmatched_sell"]');

        self::assertSame(1, $item->count());
        self::assertSame('details', $item->filter('a[data-row-target]')->attr('data-row-intent'));
        $sale = $this->rowFor($crawler, '2025-02-27');
        self::assertStringContainsString('wcześniejszy rok', $sale->filter('[data-trade-panel="details"]')->text());
        self::assertSame(1, $sale->filter('.trade__summary .flag--warning')->count());
    }

    public function testARejectedRowOpensItsEditorWithTheReason(): void
    {
        $client = static::createClient();
        $crawler = $this->importSample($client);
        $payload = $this->payload($crawler);
        $id = $payload['trades'][0]['id'];
        $payload['trades'][0]['date'] = 'nie-data';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $row = $crawler->filter('#row-'.$id);

        self::assertSame(1, $row->filter('[data-trade-panel="edit"][open]')->count());
        self::assertSame('nie-data', $row->filter('input[name$="[date]"]')->attr('value'));
        self::assertStringContainsString('Transakcja 1', $row->filter('[data-trade-panel="edit"]')->text());
        self::assertSame('edit', $crawler->filter('[data-diagnostic-code="trade.invalid"] a[data-row-target]')->attr('data-row-intent'));
        self::assertSame(1, $crawler->filter('[data-trade-ledger] .trade.is-editing')->count());
    }

    public function testRemovingARowLeavesATombstone(): void
    {
        $client = static::createClient();
        $crawler = $this->importSample($client);
        $payload = $this->payload($crawler);
        $count = count($payload['trades']);
        $id = $payload['trades'][0]['id'];
        $payload['trades'][0]['remove'] = '1';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertSame($count - 1, $crawler->filter('[data-trade-ledger] [data-trade]')->count());
        self::assertSame(1, $crawler->filter('input[name="tombstones[]"][value="'.$id.'"]')->count());
    }

    public function testTheOldInRowBulkButtonAndInlineStylesAreGone(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, self::TWO_YEARS);

        self::assertSame(0, $crawler->filter('[data-bulk-country], [name="bulk_country"]')->count());
        self::assertSame(0, $crawler->filter('[style]')->count());
    }

    /** @return list<string> every closed position, in a stable order */
    private static function fifoRows(Crawler $crawler): array
    {
        $rows = $crawler->filter('#panel-fifo [data-fragment="fifo"] tbody tr')->each(static fn (Crawler $row): string => $row->text());
        sort($rows);

        return $rows;
    }

    private function rowFor(Crawler $crawler, string $date): Crawler
    {
        foreach ($this->payload($crawler)['trades'] as $trade) {
            if ($trade['date'] === $date) {
                $row = $crawler->filter('#row-'.$trade['id']);
                self::assertSame(1, $row->count());

                return $row;
            }
        }

        self::fail('Brak transakcji z dnia '.$date);
    }

    private function importSample(KernelBrowser $client): Crawler
    {
        return $this->import($client, (string) file_get_contents(dirname(__DIR__, 2).'/examples/ibkr-activity-statement.csv'));
    }

    private function import(KernelBrowser $client, string $content): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $path = tempnam(sys_get_temp_dir(), 'pitledger');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value')],
            ['files' => [new UploadedFile($path, 'statement.csv', 'text/csv', null, true)]],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private static function sortedKeys(array $row): array
    {
        $keys = array_map('strval', array_keys($row));
        sort($keys);

        return $keys;
    }

    /** @return array<string, mixed> */
    private function payload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-workbench]')->form()->getPhpValues();
    }
}

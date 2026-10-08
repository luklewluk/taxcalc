<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class WorkbenchStateFlowTest extends WebTestCase
{
    /**
     * DEGIRO account statement: the ISIN prefix proposes the payer's country
     * (US), the tax row is the withholding.
     */
    private const string DIVIDEND_A = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend,,USD,100.00,USD,100.00,
        02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend Tax,,USD,-15.00,USD,85.00,
        CSV;

    /** No tax row: zero withholding. */
    private const string DIVIDEND_B = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        02-07-2025,06:32,02-07-2025,BBB,IE000BBBB002,Dividend,,USD,50.00,USD,50.00,
        CSV;

    /**
     * DEGIRO transactions with an `XS` ISIN and no exchange: nothing proposes a
     * country, so the trades arrive without one. The fee cell stays blank.
     */
    private const string CSPX = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        03-04-2024,09:15,CSPX,XS000CSPX001,,,1,500.0000,USD,-500.00,USD,-500.00,USD,,,,-500.00,USD,t-1001
        27-02-2025,15:41,CSPX,XS000CSPX001,,,-1,560.0000,USD,560.00,USD,560.00,USD,,,,560.00,USD,t-1002
        CSV;

    /** Other orders on the same ISIN - the same DEGIRO FIFO queue as CSPX. */
    private const string CSPX_OVERLAP = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        03-03-2024,10:05,CSPX,XS000CSPX001,,,1,450.0000,USD,-450.00,USD,-450.00,USD,,,,-450.00,USD,t-2001
        27-03-2025,11:20,CSPX,XS000CSPX001,,,-1,570.0000,USD,570.00,USD,570.00,USD,,,,570.00,USD,t-2002
        CSV;

    /**
     * One IBKR Activity Statement holding the same ticker in two currencies:
     * two FIFO queues (`ISIN@USD`, `ISIN@EUR`) of one paper. The listing
     * exchange is unknown, so no country is proposed.
     */
    private const string CSPX_TWO_CURRENCIES = <<<'CSV'
        Statement,Header,Field Name,Field Value
        Statement,Data,Title,Activity Statement
        Trades,Header,DataDiscriminator,Asset Category,Currency,Symbol,Date/Time,Quantity,T. Price,C. Price,Proceeds,Comm/Fee,Basis,Realized P/L,MTM P/L,Code
        Trades,Data,Order,Stocks,USD,CSPX,"2024-04-03, 10:00:00",1,500,500,-500,0,500,0,0,O
        Trades,Data,Order,Stocks,USD,CSPX,"2025-02-27, 10:00:00",-1,560,560,560,0,-500,60,0,C
        Trades,Data,Order,Stocks,EUR,CSPX,"2024-04-04, 10:00:00",1,480,480,-480,0,480,0,0,O
        Trades,Data,Order,Stocks,EUR,CSPX,"2025-02-28, 10:00:00",-1,520,520,520,0,-480,40,0,C
        Financial Instrument Information,Header,Asset Category,Symbol,Description,Conid,Security ID,Underlying,Listing Exch,Multiplier,Type,Code
        Financial Instrument Information,Data,Stocks,CSPX,CSPX ETF,1001,XS000CSPX001,CSPX,NOWHERE,1,ETF,
        CSV;

    /** The CSPX paper's dividend; the `XS` prefix proposes no country. */
    private const string DIVIDEND_CSPX = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        02-04-2025,06:32,02-04-2025,CSPX,XS000CSPX001,Dividend,,USD,100.00,USD,100.00,
        02-04-2025,06:32,02-04-2025,CSPX,XS000CSPX001,Dividend Tax,,USD,-15.00,USD,85.00,
        CSV;

    private const string SPY_ISIN = 'XS0000SPY003';

    /** Another ISIN: a FIFO queue of its own. */
    private const string SPY_INDEPENDENT = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        03-03-2024,10:05,SPY,XS0000SPY003,,,1,450.0000,USD,-450.00,USD,-450.00,USD,,,,-450.00,USD,t-3001
        27-03-2025,11:20,SPY,XS0000SPY003,,,-1,570.0000,USD,570.00,USD,570.00,USD,,,,570.00,USD,t-3002
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

    public function testIndependentUploadIsAddedAndReuploadDoesNotDuplicateIt(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['a.csv' => self::DIVIDEND_A]);

        $crawler = $this->uploadMore($client, $crawler, ['b.csv' => self::DIVIDEND_B]);
        self::assertSame(2, $crawler->filter('[data-editor-body="dividends"] > tr')->count());

        $crawler = $this->uploadMore($client, $crawler, ['a-again.csv' => self::DIVIDEND_A]);
        self::assertSame(2, $crawler->filter('[data-editor-body="dividends"] > tr')->count());
        // An upload that changed nothing is a review item, not a strip notice:
        // otherwise re-uploading a file gives back an identical page with no
        // explanation at all.
        self::assertSame(1, $crawler->filter('[data-diagnostic-code="upload.nothing_added"]')->count());
        self::assertStringContainsString('niczego nie dodano', mb_strtolower($crawler->filter('#panel-attention')->text()));
    }

    public function testStableDividendIdAndTombstoneSurviveEditDeleteAndReupload(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['a.csv' => self::DIVIDEND_A]);
        $payload = $this->payload($crawler);
        $id = $payload['dividends'][0]['id'];
        $payload['dividends'][0]['name'] = 'AAA po korekcie';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        self::assertSame($id, $crawler->filter('input[name="dividends[0][id]"]')->attr('value'));
        self::assertSame('AAA po korekcie', $crawler->filter('input[name="dividends[0][name]"]')->attr('value'));

        $payload = $this->payload($crawler);
        $payload['dividends'][0]['remove'] = '1';
        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        self::assertSame(0, $crawler->filter('[data-editor-body="dividends"] > tr')->count());
        self::assertSame(1, $crawler->filter('input[name="tombstones[]"][value="'.$id.'"]')->count());

        $crawler = $this->uploadMore($client, $crawler, ['a-again.csv' => self::DIVIDEND_A]);
        self::assertSame(0, $crawler->filter('[data-editor-body="dividends"] > tr')->count());
    }

    public function testBatchTouchingAnExistingFifoQueueIsRejectedAtomically(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['cspx.csv' => self::CSPX]);
        $crawler = $this->fillTradeCountries($client, $crawler);

        $crawler = $this->uploadMore($client, $crawler, [
            'independent.csv' => self::SPY_INDEPENDENT,
            'overlap.csv' => self::CSPX_OVERLAP,
        ]);

        self::assertSame(2, $crawler->filter('[data-trade-ledger] [data-trade]')->count());
        self::assertStringContainsString('cały upload odrzucono', mb_strtolower($crawler->filter('body')->text()));
        self::assertSame(0, $crawler->filter('input[value="SPY"]')->count());
        self::assertSame(0, $crawler->filter('input[value="'.self::SPY_ISIN.'"]')->count());
    }

    public function testIndependentFifoQueueCanBeAdded(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['cspx.csv' => self::CSPX]);
        $crawler = $this->fillTradeCountries($client, $crawler);
        $crawler = $this->uploadMore($client, $crawler, ['spy.csv' => self::SPY_INDEPENDENT]);

        self::assertSame(4, $crawler->filter('[data-trade-ledger] [data-trade]')->count());
        self::assertSame(2, $crawler->filter('[data-trade-ledger] input[name$="[symbol]"][value="'.self::SPY_ISIN.'"]')->count());
    }

    public function testInvalidEditFailsClosedAndPreservesTheSubmittedValue(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['a.csv' => self::DIVIDEND_A]);
        $payload = $this->payload($crawler);
        $payload['dividends'][0]['date'] = 'nie-data';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertSame(1, $crawler->filter('.result-unavailable')->count());
        self::assertSame('nie-data', $crawler->filter('input[name="dividends[0][date]"]')->attr('value'));
        self::assertStringNotContainsString('PIT-38(18)', $crawler->filter('#panel-summary')->text());
    }

    public function testMissingCountriesOpenOneBlockingAttentionItemPerInstrument(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['cspx.csv' => self::CSPX]);

        $item = $crawler->filter('[data-diagnostic-code="country.missing_instrument"]');

        self::assertSame('true', $crawler->filter('#tab-attention')->attr('aria-selected'));
        self::assertSame(1, $item->count());
        self::assertStringContainsString('2 transakcj', $item->text());
        self::assertStringContainsString('PIT/ZG', $item->text());
        self::assertSame('1', trim($crawler->filter('[data-fragment="attentionCounter"] .attention-count')->text()));
        self::assertSame(1, $crawler->filter('.result-unavailable')->count());
    }

    /**
     * The two IBKR currency pools of one ticker are one paper, so they share a
     * single attention item - the country is a property of the instrument, not
     * of the currency the FIFO queue is keyed on.
     */
    public function testTwoCurrencyPoolsOfOneTickerShareOneAttentionItem(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['statement.csv' => self::CSPX_TWO_CURRENCIES]);
        $pools = array_unique(array_column($this->payload($crawler)['trades'], 'pool'));
        sort($pools);
        self::assertSame(['XS000CSPX001@EUR', 'XS000CSPX001@USD'], $pools, 'Two FIFO queues of one paper.');

        $item = $crawler->filter('[data-diagnostic-code="country.missing_instrument"]');

        self::assertSame(1, $item->count());
        self::assertStringContainsString('4 transakcj', $item->text());
    }

    /**
     * Trades and dividends of one paper are two problems with two answers: the
     * dividend declares the payer's residence (which caps the treaty credit),
     * the trades the place of disposal. One shared item would offer a button
     * that writes the wrong value into one of the two tabs.
     */
    public function testTradesAndADividendOfOneInstrumentAreTwoAttentionItems(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['cspx.csv' => self::CSPX, 'div.csv' => self::DIVIDEND_CSPX]);

        $items = $crawler->filter('[data-diagnostic-code="country.missing_instrument"]');

        self::assertSame(2, $items->count());
        self::assertSame(2, $items->filter('[data-country-group-apply]')->count());

        $texts = $items->each(static fn (Crawler $node): string => $node->text());
        $joined = implode(' ', $texts);
        self::assertStringContainsString('2 transakcj', $joined);
        self::assertStringContainsString('1 dywidend', $joined);
        self::assertStringContainsString('Zastosuj do wszystkich (2)', $joined);
        self::assertStringContainsString('Zastosuj do wszystkich (1)', $joined);
    }

    public function testTheGroupActionFillsOnlyItsOwnTabWithoutJavascript(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['cspx.csv' => self::CSPX, 'div.csv' => self::DIVIDEND_CSPX]);

        $groupId = $this->groupIdFor($crawler, 'transakcj');
        $payload = $this->payload($crawler);
        $payload['country_group_apply'] = $groupId;
        $payload['country_group_value'] = [$groupId => 'US'];

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $result = $this->payload($crawler);

        self::assertSame(['US', 'US'], array_column($result['trades'], 'country'));
        // The dividend is a separate answer and must stay untouched.
        self::assertSame([''], array_column($result['dividends'], 'country'));
        self::assertSame(1, $crawler->filter('[data-diagnostic-code="country.missing_instrument"]')->count());

        $groupId = $this->groupIdFor($crawler, 'dywidend');
        $payload = $this->payload($crawler);
        $payload['country_group_apply'] = $groupId;
        $payload['country_group_value'] = [$groupId => 'IE'];

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $result = $this->payload($crawler);

        self::assertSame(['US', 'US'], array_column($result['trades'], 'country'));
        self::assertSame(['IE'], array_column($result['dividends'], 'country'));
        self::assertSame(0, $crawler->filter('[data-diagnostic-code="country.missing_instrument"]')->count());
    }

    public function testTheGroupActionNeverOverwritesWithoutTheOverwriteFlag(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['cspx.csv' => self::CSPX]);

        $groupId = $this->groupIdFor($crawler, 'transakcj');
        $payload = $this->payload($crawler);
        $payload['trades'][0]['country'] = 'CA';
        $payload['country_group_apply'] = $groupId;
        $payload['country_group_value'] = [$groupId => 'US'];

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $result = $this->payload($crawler);

        self::assertSame(['CA', 'US'], array_column($result['trades'], 'country'));
        self::assertSame(1, $crawler->filter('[data-diagnostic-code="country.conflict_instrument"].attention-item--review')->count());
    }

    /**
     * A conflict *inside* one tab is a real data problem - one sale has one
     * place of disposal - so the unify action still exists for it.
     */
    public function testTheOverwriteFlagUnifiesAConflictWithinOneTab(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['cspx.csv' => self::CSPX]);
        $payload = $this->payload($crawler);
        $payload['trades'][0]['country'] = 'US';
        $payload['trades'][1]['country'] = 'CA';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $conflict = $crawler->filter('[data-diagnostic-code="country.conflict_instrument"]');
        self::assertSame(1, $conflict->count());

        $groupId = (string) $conflict->filter('[data-country-group-apply]')->attr('data-country-group-apply');
        $payload = $this->payload($crawler);
        $payload['country_group_apply'] = $groupId;
        $payload['country_group_value'] = [$groupId => 'US'];
        $payload['country_group_overwrite'] = [$groupId => '1'];

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $result = $this->payload($crawler);

        self::assertSame(['US', 'US'], array_column($result['trades'], 'country'));
        self::assertSame(0, $crawler->filter('[data-diagnostic-code="country.conflict_instrument"]')->count());
    }

    /**
     * A mis-click must not throw away a PIT result that was already on screen.
     */
    public function testApplyingWithoutChoosingACountryKeepsTheResultVisible(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['a.csv' => self::DIVIDEND_A]);
        $payload = $this->payload($crawler);
        $payload['country_group_apply'] = 'instr-0000000000000000';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertSame(1, $crawler->filter('[data-diagnostic-code="country.group_missing"]')->count());

        $payload = $this->payload($crawler);
        $groupId = 'instr-'.substr(hash('sha256', 'dividends|name:AAA'), 0, 16);
        $payload['country_group_apply'] = $groupId;
        $payload['country_group_value'] = [$groupId => ''];

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertSame(1, $crawler->filter('[data-diagnostic-code="country.group_value_missing"].attention-item--review')->count());
        self::assertSame(0, $crawler->filter('.result-unavailable')->count());
    }

    public function testTheGroupActionSkipsHtmlValidationAndPostsForReal(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['cspx.csv' => self::CSPX]);
        $button = $crawler->filter('[data-country-group-apply]');

        self::assertSame(1, $button->count());
        self::assertNotNull($button->attr('formnovalidate'));
        self::assertStringContainsString('/kalkulator/wynik', (string) $button->attr('formaction'));

        // The workbench bar has to skip validation too: the form holds required
        // country selects with blank values, and interactive validation runs
        // before the submit event, so without this every bar button is dead in
        // exactly the state the bulk action exists for.
        foreach ($crawler->filter('.workbench-bar button[type="submit"]') as $bar) {
            self::assertTrue($bar->hasAttribute('formnovalidate'));
        }
    }

    /**
     * The treaty table is the vocabulary of the select, not the whole world. A
     * country outside it used to render as "nothing selected", so the next post
     * silently replaced it with a blank.
     */
    public function testACountryOutsideTheTreatyTableSurvivesARoundTrip(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['cspx.csv' => self::CSPX]);
        $payload = $this->payload($crawler);
        $payload['trades'][0]['country'] = 'BR';
        $payload['trades'][1]['country'] = 'BR';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertSame('BR', $this->payload($crawler)['trades'][0]['country']);
        self::assertStringContainsString(
            'poza tabelą umów',
            $crawler->filter('select[name="trades[0][country]"] option[value="BR"]')->text(),
        );
        // A stock position needs no treaty rate, so an untabled country blocks
        // nothing here - it just has to stop disappearing.
        self::assertSame(0, $crawler->filter('.result-unavailable')->count());
    }

    public function testADividendRowIsReachableFromTheAttentionPanel(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['div.csv' => self::DIVIDEND_CSPX]);
        $rowId = $crawler->filter('[data-editor-body="dividends"] > tr')->attr('data-row-id');

        self::assertNotNull($rowId);
        self::assertSame(1, $crawler->filter('#row-'.$rowId)->count());
    }

    public function testDisagreeingTradeCountriesAreAReviewItemThatKeepsTheResult(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['cspx.csv' => self::CSPX]);
        $payload = $this->payload($crawler);
        $payload['trades'][0]['country'] = 'US';
        $payload['trades'][1]['country'] = 'CA';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $result = $this->payload($crawler);

        self::assertSame('US', $result['trades'][0]['country']);
        self::assertSame('CA', $result['trades'][1]['country']);
        self::assertSame(1, $crawler->filter('[data-diagnostic-code="country.conflict_instrument"].attention-item--review')->count());
        self::assertSame(0, $crawler->filter('.result-unavailable')->count());
        self::assertSame('true', $crawler->filter('#tab-summary')->attr('aria-selected'));
    }

    public function testAttentionTabAlwaysHasAnExplicitEmptyState(): void
    {
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['dividend.csv' => self::DIVIDEND_A]);

        self::assertSame(1, $crawler->filter('#panel-attention .attention-empty')->count());
        self::assertStringContainsString('Nic nie wymaga uwagi', $crawler->filter('#panel-attention')->text());
        self::assertSame('0', trim($crawler->filter('[data-fragment="attentionCounter"] .attention-count')->text()));
    }

    public function testOnlyStrictConnectionFeeBecomesAnIncludedPitCost(): void
    {
        $account = "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n"
            ."02-01-2025,00:00,02-01-2025,,,DEGIRO Exchange Connection Fee,,EUR,-2.50,EUR,100.00,\n"
            ."03-01-2025,00:00,03-01-2025,,,DEGIRO Transaction Fee,,EUR,-9.00,EUR,91.00,\n";
        $client = static::createClient();
        $crawler = $this->firstImport($client, ['account.csv' => $account]);

        self::assertSame(1, $crawler->filter('[data-editor-body="fees"] > tr')->count());
        self::assertStringContainsString('10,75', $crawler->filter('#panel-summary')->text());
        self::assertSame(1, $crawler->filter('input[name="fees[0][included]"][type="checkbox"][checked]')->count());
        self::assertStringNotContainsString('DEGIRO Transaction Fee', $crawler->filter('#panel-fees')->text());
    }

    /** @param array<string, string> $files */
    private function firstImport(KernelBrowser $client, array $files): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $payload = [
            'tax_year' => '2025',
            '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
        ];

        return $client->request('POST', '/kalkulator/import', $payload, ['files' => $this->uploads($files)]);
    }

    /** @param array<string, string> $files */
    private function uploadMore(KernelBrowser $client, Crawler $crawler, array $files): Crawler
    {
        return $client->request(
            'POST',
            '/kalkulator/import',
            $this->payload($crawler),
            ['files' => $this->uploads($files)],
        );
    }

    private function fillTradeCountries(KernelBrowser $client, Crawler $crawler): Crawler
    {
        $payload = $this->payload($crawler);
        foreach ($payload['trades'] as &$trade) {
            $trade['country'] = 'US';
        }
        unset($trade);

        return $client->request('POST', '/kalkulator/wynik', $payload);
    }

    /** @param array<string, string> $files @return list<UploadedFile> */
    private function uploads(array $files): array
    {
        $uploads = [];
        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'pit-workbench');
            self::assertIsString($path);
            file_put_contents($path, $content);
            $this->tempFiles[] = $path;
            $uploads[] = new UploadedFile($path, $name, 'text/csv', null, true);
        }

        return $uploads;
    }

    /**
     * The group id of the attention item whose text mentions `$scopeWord`, so a
     * test can address the trades group or the dividends group deliberately.
     */
    private function groupIdFor(Crawler $crawler, string $scopeWord): string
    {
        foreach ($crawler->filter('[data-diagnostic-code="country.missing_instrument"]') as $node) {
            $item = new Crawler($node);
            if (str_contains($item->text(), $scopeWord)) {
                return (string) $item->filter('[data-country-group-apply]')->attr('data-country-group-apply');
            }
        }

        self::fail(sprintf('Brak pozycji "Wymaga uwagi" dla zakresu %s.', $scopeWord));
    }

    /** @return array<string, mixed> */
    private function payload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-workbench]')->form()->getPhpValues();
    }
}

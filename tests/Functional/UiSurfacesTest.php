<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Guards the single-screen workbench and its progressive enhancement hooks. */
final class UiSurfacesTest extends WebTestCase
{
    /**
     * DEGIRO account statement: a US payment with 30 withheld and an Irish one
     * with nothing withheld. The countries come from the ISIN prefixes.
     */
    private const string DIVIDENDS = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend,,USD,100.00,USD,100.00,
        02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend Tax,,USD,-30.00,USD,70.00,
        02-07-2025,06:32,02-07-2025,BBB,IE000BBBB002,Dividend,,USD,50.00,USD,120.00,
        CSV;

    /**
     * One DEGIRO position without a country (an `XS` ISIN and no exchange
     * columns) and without a reported fee, so the przychód is the settled cash.
     */
    private const string DEGIRO_TRADES = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        03-04-2024,09:15,CSPX,XS000CSPX001,,,3,507.5782,USD,-1523.98,USD,-1523.98,USD,,,,-1523.98,USD,t-1001
        27-02-2025,15:41,CSPX,XS000CSPX001,,,-3,560.00,USD,1680.00,USD,1680.00,USD,,,,1680.00,USD,t-1002
        CSV;

    /**
     * A dividend whose ISIN prefix names a country with no treaty rate
     * configured: `ZZ` is a well-formed code that no treaty table carries.
     */
    private const string DIVIDEND_WITHOUT_TREATY = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        02-04-2025,06:32,02-04-2025,AAA,ZZ000AAAA001,Dividend,,USD,100.00,USD,100.00,
        02-04-2025,06:32,02-04-2025,AAA,ZZ000AAAA001,Dividend Tax,,USD,-5.00,USD,95.00,
        CSV;

    /**
     * Single-lot DEGIRO position with a reported sell fee, so the revenue/cost
     * split is actually made: 3 bought and 3 sold, fee 1,25 on each leg.
     */
    private const string DEGIRO_WITH_FEE = <<<'CSV'
        Date,Time,Product,ISIN,Reference Exchange,Execution Venue,Quantity,Price,,Value,,Transaction and/or third,,Total,,Order ID
        03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,3,507.5782,USD,-1522.73,USD,-1.25,USD,-1523.98,USD,buy-1
        27-02-2025,15:41,ALFA CORP,US000ALFA001,NDQ,XNAS,-3,1493.3333,USD,4480.00,USD,-1.25,USD,4478.75,USD,sell-1
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

    public function testLandingExplainsThePrivacyModel(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $text = $crawler->filter('body')->text();

        self::assertStringContainsString('Co się dzieje z moimi danymi?', $text);
        self::assertStringContainsString('Aplikacja nie ma bazy danych', $text);
        self::assertStringContainsString('niczego nie zapisuje', $text);
        self::assertStringNotContainsString('Sprawdź →', $text);
    }

    public function testUploadPageHasNoStepperAndOffersOnlySupportedYears(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        self::assertSame(0, $crawler->filter('.progress, .stepper')->count());
        self::assertSame(['2026', '2025', '2024', '2023', '2022', '2021'], $crawler
            ->filter('select[name="tax_year"] option')
            ->extract(['value']));
        self::assertSame(1, $crawler->filter('[data-role="dropzone"] input[type="file"][multiple]')->count());
    }

    /**
     * The upload page links to the guide instead of carrying it.
     */
    public function testTheCalculatorLinksToTheFilesGuideInsteadOfCarryingIt(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        self::assertSame(0, $crawler->filter('main details')->count());
        self::assertStringNotContainsString('Performance & Reports', $crawler->filter('main')->text());
        self::assertGreaterThan(0, $crawler->filter('main a[href="/skad-wziac-pliki"]')->count());
        // Under the submit button, not among the file hints.
        self::assertSame(1, $crawler->filter('form .actions + p a[href="/skad-wziac-pliki"]')->count());

        $guide = $client->request('GET', '/skad-wziac-pliki');
        self::assertResponseIsSuccessful();
        foreach (['ibkr', 'degiro', 'czego-nie-rozliczy'] as $section) {
            self::assertCount(1, $guide->filter('section#'.$section), $section);
        }
        self::assertSame('/kalkulator', $guide->filter('main a.button--primary')->attr('href'));
    }

    public function testFirstImportGoesStraightToTheSevenTabWorkbench(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[data-workbench]')->count());
        self::assertSame(0, $crawler->filter('.progress, .stepper')->count());
        self::assertSame(
            ['PIT-38 / PIT-ZG', 'Wymaga uwagi 0', 'Transakcje', 'FIFO', 'Dywidendy', 'Opłaty', 'Ustawienia'],
            $crawler->filter('[role="tablist"] [role="tab"]')->each(static fn (Crawler $node): string => $node->text()),
        );
        self::assertSame(7, $crawler->filter('[role="tabpanel"]')->count());
        self::assertSame(0, $crawler->filter('[role="tabpanel"][hidden]')->count(), 'without JavaScript all panels stay visible');
        self::assertStringContainsString('Wkład z zaimportowanych danych', $crawler->filter('#panel-summary')->text());
    }

    /**
     * The settings panel deliberately sits outside every `data-fragment`
     * container: a control inside one would be wiped by the debounce the moment
     * the user changed anything else, taking their choice with it.
     */
    public function testSettingsPanelOffersBothChoicesAndIsNotAnAjaxFragment(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $panel = $crawler->filter('#panel-settings');

        self::assertSame(1, $panel->filter('select[name="country_source"]')->count());
        self::assertSame(1, $panel->filter('select[name="credit_method"]')->count());
        self::assertSame(0, $panel->filter('[data-fragment]')->count());

        // Defaults: listing exchange, conservative (KIS) variant.
        self::assertSame('exchange', $panel->filter('select[name="country_source"] option[selected]')->attr('value'));
        self::assertSame('conservative', $panel->filter('select[name="credit_method"] option[selected]')->attr('value'));

        // Both readings have to be explained where the choice is made.
        self::assertStringContainsString('giełd', $panel->text());
        self::assertStringContainsString('ISIN', $panel->text());
        self::assertStringContainsString('II FSK 1171/22', $panel->text());
    }

    /**
     * The editor tables are not AJAX fragments, so a setting that changes what
     * is in them must submit for real - otherwise Transakcje would keep showing
     * the old countries while the summary already used the new ones.
     */
    public function testChangingASettingSubmitsForRealInsteadOfRecalculatingInTheBackground(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $panel = $crawler->filter('#panel-settings');

        // No button: a changed setting submits the whole form by itself.
        self::assertSame(0, $panel->filter('button')->count());
        self::assertSame(2, $panel->filter('select[data-full-reload]')->count());
        self::assertStringContainsString('/kalkulator/wynik', (string) $crawler->filter('form[data-workbench]')->attr('action'));

        $script = (string) file_get_contents(\dirname(__DIR__, 2).'/public/js/app.js');
        self::assertStringContainsString('data-full-reload', $script);
        self::assertStringContainsString('workbench.requestSubmit()', $script);
        self::assertStringNotContainsString('data-apply-settings', $script);
    }

    public function testASelectedSettingSurvivesTheRoundTrip(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $payload = $this->payload($crawler);
        $payload['country_source'] = 'isin';
        $payload['credit_method'] = 'nsa';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $panel = $crawler->filter('#panel-settings');

        self::assertSame('isin', $panel->filter('select[name="country_source"] option[selected]')->attr('value'));
        self::assertSame('nsa', $panel->filter('select[name="credit_method"] option[selected]')->attr('value'));
    }

    public function testTransactionsEditorCarriesTheCompleteLogicalTradeState(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::DEGIRO_TRADES]);
        $form = $crawler->filter('form[data-workbench]')->form()->getPhpValues();

        self::assertCount(2, $form['trades']);
        foreach ($form['trades'] as $trade) {
            self::assertIsArray($trade);
            foreach (['id', 'broker', 'pool', 'symbol', 'name', 'country', 'date', 'time', 'side', 'quantity', 'currency', 'total', 'unit_price', 'price_currency', 'commission', 'autofx', 'external_id', 'source'] as $field) {
                self::assertArrayHasKey($field, $trade);
            }
        }
        self::assertSame('507.5782', $form['trades'][0]['unit_price']);
        self::assertSame('USD', $form['trades'][0]['price_currency']);
        self::assertSame(1, $crawler->filter('[data-trade-ledger] [data-trade-panel="edit"] label.remove-toggle input[name="trades[0][remove]"]')->count());
    }

    public function testWorkbenchBarKeepsYearCountsPrivacyUploadsAndExportsAvailable(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $bar = $crawler->filter('.workbench-bar');

        self::assertSame(1, $bar->filter('select[name="tax_year"]')->count());
        self::assertSame(1, $bar->filter('input[type="file"][multiple]')->count());
        self::assertSame(1, $bar->filter('button[formaction$="/import"]')->count());
        self::assertSame(1, $bar->filter('button[formaction$="raport.csv"]')->count());
        self::assertSame(1, $bar->filter('button[formaction$="raport"]')->count());
        self::assertStringContainsString('Dane tylko w tej karcie', $crawler->filter('.workbench-heading')->text());
    }

    public function testAjaxRecalculationReturnsVersionedReplaceableFragments(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $payload = $this->payload($crawler);
        $payload['revision'] = '17';

        $client->request('POST', '/kalkulator/wynik', $payload, [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(17, $json['version']);
        self::assertTrue($json['ok']);
        self::assertSame(
            ['summary', 'fifo', 'attention', 'attentionCounter', 'dividendResults', 'messages', 'counters'],
            array_keys($json['fragments']),
        );
    }

    public function testFifoCsvAndPrintCarryAuditPricesWithoutMovingTheTradeEditor(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::DEGIRO_TRADES]);
        $payload = $this->payload($crawler);
        foreach ($payload['trades'] as &$trade) {
            $trade['country'] = 'US';
        }
        unset($trade);

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        self::assertSame(0, $crawler->filter('#panel-fifo [data-trade-ledger]')->count());
        self::assertSame(1, $crawler->filter('#panel-transactions [data-trade-ledger]')->count());
        $fifo = $crawler->filter('#panel-fifo')->text();
        self::assertStringContainsString('507,5782 USD', $fifo);
        self::assertStringContainsString('560,00 USD', $fifo);

        $payload = $this->payload($crawler);
        $client->request('POST', '/kalkulator/raport.csv', $payload);
        $csv = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Cena/szt. zakupu', $csv);
        self::assertStringContainsString('507.5782', $csv);
        self::assertStringContainsString('AKTUALNY STAN - LOGICZNE TRANSAKCJE FIFO', $csv);

        $crawler = $client->request('POST', '/kalkulator/raport', $payload);
        self::assertStringContainsString('507,5782 USD', $crawler->filter('body')->text());
    }

    /**
     * The two leg columns used to be headed just "PLN", which said nothing about
     * which one was the cost and which the revenue - and after the sell fee
     * moved into the costs the buy-leg conversion is no longer the cost anyway.
     *
     * The leaf column count stays 17 either way, so a forgotten colspan would
     * misalign the two header rows with nothing to catch it. Hence the colspans
     * are asserted, not just the labels.
     */
    public function testFifoNamesTheRevenueAndCostColumnsAndKeepsTheHeaderAligned(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro.csv' => self::DEGIRO_WITH_FEE]);
        $fifo = $crawler->filter('#panel-fifo');

        self::assertStringContainsString('Przychód PLN', $fifo->text());
        self::assertStringContainsString('Koszt PLN', $fifo->text());
        self::assertSame(0, $fifo->filter('thead th')->reduce(
            static fn ($th): bool => 'PLN' === trim($th->text()),
        )->count());

        $groups = $fifo->filter('thead tr')->eq(0)->filter('th[colspan]');
        self::assertSame(2, $groups->count());
        foreach ($groups as $group) {
            self::assertSame('6', $group->getAttribute('colspan'));
        }
        self::assertSame(6, $fifo->filter('thead tr')->eq(1)->filter('th')->count() / 2);

        // The "Total" cell must keep showing the settled cash from the file, not
        // the grossed-up revenue - 4478,75 next to a 1,25 fee, never 4480,00.
        self::assertStringContainsString("4\u{00A0}478,75 USD", $fifo->text());
        self::assertStringNotContainsString("4\u{00A0}480,00 USD", $fifo->text());
    }

    public function testFifoFootnoteGivesTheFormulaInsteadOfTheOldAuditOnlyClaim(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro.csv' => self::DEGIRO_WITH_FEE]);
        $note = $crawler->filter('#panel-fifo [data-fragment="fifo"] .note')->first()->text();

        self::assertStringContainsString('Total + prowizja + AutoFX', $note);
        self::assertStringContainsString('prowizja zakupu jest już w', $note);
        self::assertStringNotContainsString('opłat nie doliczono drugi raz', $note);
    }

    /**
     * A DEGIRO trade with a blank fee cell has a null disposal cost, so this
     * exercises the branch that would be a 500 if a template added the two
     * costs itself.
     */
    public function testAPositionWithoutAReportedSellFeeStillRenders(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => self::DEGIRO_TRADES]);
        $payload = $this->payload($crawler);
        foreach ($payload['trades'] as &$trade) {
            $trade['country'] = 'US';
        }
        unset($trade);

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertSame(0, $crawler->filter('.message--error')->count());
        self::assertGreaterThan(0, $crawler->filter('#panel-fifo tbody tr')->count());
        self::assertStringContainsString('nie zgłasza prowizji', $crawler->filter('#panel-fifo [data-fragment="fifo"] .note')->last()->text());
    }

    /**
     * Without the date column the NBP rate date is unexplainable: a payment
     * booked on the 30th legitimately carries a rate from the 29th, and over a
     * holiday run it can be days earlier. The reader has to see both.
     */
    public function testDividendResultsShowTheDateTheRateWasTakenFrom(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['div.csv' => self::DIVIDENDS]);
        $results = $crawler->filter('.dividend-results');

        self::assertStringContainsString('Data', $results->filter('thead')->text());
        self::assertStringContainsString('2025-04-02', $results->filter('tbody tr')->first()->text());
    }

    /**
     * The setting picks the reading, and the summary then shows that one only -
     * see CreditMethodOutputTest for every other surface.
     */
    public function testTheChosenVariantIsTheOnlyOneInTheSummary(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['div.csv' => self::DIVIDENDS]);
        $payload = $this->payload($crawler);
        $payload['credit_method'] = 'nsa';

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);
        $summary = $crawler->filter('#panel-summary');

        self::assertStringNotContainsString('NSA', $summary->text());
        self::assertStringNotContainsString('alternatywn', mb_strtolower($summary->text()));
        self::assertStringNotContainsString('zachowawcz', mb_strtolower($summary->text()));
    }

    public function testTheTreatyRateWarningDisappearsUnderNsa(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['unknown.csv' => self::DIVIDEND_WITHOUT_TREATY]);

        self::assertSame(1, $crawler->filter('[data-diagnostic-code="dividend.treaty_rate_missing"]')->count());

        $payload = $this->payload($crawler);
        $payload['credit_method'] = 'nsa';
        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        // Under the NSA reading the treaty cap plays no part, so the item is
        // noise rather than something to act on.
        self::assertSame(0, $crawler->filter('[data-diagnostic-code="dividend.treaty_rate_missing"]')->count());
    }

    public function testDividendTabSummarySumsWhatFeedsThePitFields(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['div.csv' => self::DIVIDENDS]);
        $summary = $crawler->filter('[data-fragment="dividendResults"] .dividend-summary');

        self::assertSame(1, $summary->count());
        $text = $summary->text();

        // The four PIT-38 dividend fields for 2025.
        foreach (['47', '48', '49', '51'] as $field) {
            self::assertStringContainsString('Pole '.$field, $text);
        }
        self::assertStringContainsString('Przychód brutto', $text);
        self::assertStringContainsString('Podatek polski 19%', $text);
        self::assertStringContainsString('Podatek pobrany za granicą', $text);
    }

    public function testDividendSummaryShowsThePerCountryBreakdown(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['div.csv' => self::DIVIDENDS]);
        $table = $crawler->filter('[data-fragment="dividendResults"] .dividend-countries');

        self::assertSame(1, $table->count());
        self::assertSame(2, $table->filter('tbody tr')->count(), 'US and IE');
        self::assertStringContainsString('bez PIT/ZG', $table->text());
    }

    public function testMissingExecutionPriceRendersAsADashInFifo(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['trades.csv' => <<<'CSV'
            Date,Time,Product,ISIN,Reference Exchange,Execution Venue,Quantity,Price,,Value,,Transaction and/or third,,Total,,Order ID
            15-03-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,1,,USD,-10.00,USD,0.00,USD,-10.00,USD,buy-1
            20-09-2025,14:30,ALFA CORP,US000ALFA001,NDQ,XNAS,-1,15.0000,USD,15.00,USD,0.00,USD,15.00,USD,sell-1
            CSV]);

        $fifo = $crawler->filter('#panel-fifo');
        self::assertSame(1, $fifo->filter('tbody tr')->count());
        self::assertStringContainsString('—', $fifo->text());
        self::assertStringContainsString('15,0000 USD', $fifo->text());
    }

    public function testDividendResultsExplainTheChosenCreditNumericallyWithoutTheOldLongWarning(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $text = $crawler->filter('[data-fragment="dividendResults"]')->text();

        foreach (['Stawka umowna', 'Pobrany PLN', 'Podatek PL', 'Odliczenie', 'Do zapłaty'] as $heading) {
            self::assertStringContainsString($heading, $text);
        }
        foreach (['KIS', 'NSA', 'zachowawcz', 'Różnica'] as $absent) {
            self::assertStringNotContainsString($absent, $text);
        }
        self::assertStringContainsString('15%', $text);
        self::assertStringNotContainsString('Pobrano podatek wyższy niż stawka umowna', $crawler->filter('body')->text());
    }

    public function testUnknownTreatyRateIsReviewOnlyAndKeepsThePitResultVisible(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['unknown.csv' => self::DIVIDEND_WITHOUT_TREATY]);

        self::assertSame(1, $crawler->filter('[data-diagnostic-code="dividend.treaty_rate_missing"].attention-item--review')->count());
        self::assertSame(0, $crawler->filter('.result-unavailable')->count());
        self::assertSame('true', $crawler->filter('#tab-summary')->attr('aria-selected'));
        self::assertStringContainsString('Brak skonfigurowanej stawki umownej', $crawler->filter('#panel-attention')->text());
    }

    /**
     * An Activity Statement holds trades, dividends and withholding, so the file
     * itself names no tab: the broken dividend row has to carry its destination.
     */
    public function testImportDiagnosticsUseAnExplicitDestinationInsteadOfMessageText(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['broken-dividend.csv' => <<<'CSV'
            Statement,Header,Field Name,Field Value
            Statement,Data,Title,Activity Statement
            Dividends,Header,Currency,Date,Description,Amount
            Dividends,Data,USD,nie-data,AAA(US000AAAA001) Cash Dividend USD 1.00 per Share (Ordinary Dividend),100
            Dividends,Data,Total,,,100
            Withholding Tax,Header,Currency,Date,Description,Amount,Code
            Withholding Tax,Data,USD,2025-04-02,AAA(US000AAAA001) Cash Dividend USD 1.00 per Share - US Tax,-15,
            Withholding Tax,Data,Total,,,-15,
            CSV]);

        $issue = $crawler->filter('[data-diagnostic-code="import.invalid"]');
        self::assertSame(1, $issue->count());
        self::assertSame('dividends', $issue->filter('[data-attention-target]')->attr('data-attention-target'));
        self::assertSame('true', $crawler->filter('#tab-attention')->attr('aria-selected'));
    }

    public function testTabAndDebounceScriptIncludesKeyboardAndStaleResponseGuards(): void
    {
        $script = self::assetContents('js/app.js');

        foreach (['ArrowRight', 'ArrowLeft', 'Home', 'End', 'hashchange', 'AbortController', 'payload.version !== revision', 'recalculate(false)', 'recalculate(true)', '450'] as $needle) {
            self::assertStringContainsString($needle, $script);
        }
    }

    public function testWideWorkbenchAndMobilePrintRulesArePresent(): void
    {
        $css = self::assetContents('css/app.css');
        $print = self::assetContents('css/print.css');

        self::assertMatchesRegularExpression('/\.container--workbench\s*\{[^}]*max-width:\s*112rem/s', $css);
        self::assertMatchesRegularExpression('/\.workbench-panel[^}]*width:\s*100%/s', $css);
        self::assertStringContainsString('@media (max-width: 54rem)', $css);
        self::assertStringContainsString('details:not([open]) > *:not(summary)', $print);
    }

    public function testPrintableReportIsReadOnlyAndExpandsItsDisclosure(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['dividends.csv' => self::DIVIDENDS]);
        $crawler = $client->request('POST', '/kalkulator/raport', $this->payload($crawler));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('input, select, textarea')->count());
        self::assertGreaterThan(0, $crawler->filter('details[open]')->count());
        self::assertStringContainsString('Wersja tylko do odczytu', $crawler->filter('body')->text());
        self::assertSame(1, $crawler->filter('button[data-action="print"][hidden]')->count());
        self::assertMatchesRegularExpression('/Ctrl\+P.*⌘\+P/u', $crawler->filter('[data-role="print-fallback"]')->text());
    }

    /** @return list<string> */
    private static function openDisclosures(Crawler $crawler): array
    {
        $open = [];
        foreach ($crawler->filter('details') as $node) {
            if ($node->hasAttribute('open')) {
                $open[] = trim($node->textContent);
            }
        }

        return $open;
    }

    private static function assetContents(string $path): string
    {
        $file = dirname(__DIR__, 2).'/public/'.$path;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /** @param array<string, string> $files filename => content */
    private function import(KernelBrowser $client, array $files, string $year = '2025'): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $uploads = [];

        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'pitui');
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

    /** @return array<string, mixed> */
    private function payload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-workbench]')->form()->getPhpValues();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The DEGIRO journey end to end: upload both official exports, review the rows
 * with the country the ISIN suggests already filled in, calculate, download.
 */
final class DegiroFlowTest extends WebTestCase
{
    private const string TRANSACTIONS = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,3,507.5782,USD,-1522.73,USD,-1522.73,USD,,-1.25,USD,-1523.98,USD,aaa-0001
        13-06-2024,10:02,ALFA CORP,US000ALFA001,NDQ,XNAS,5,470.0000,USD,-2350.00,USD,-2350.00,USD,,-0.99,USD,-2350.99,USD,aaa-0002
        27-02-2025,15:41,ALFA CORP,US000ALFA001,NDQ,XNAS,-8,560.0000,USD,4480.00,USD,4480.00,USD,,-1.25,USD,4478.75,USD,aaa-0003
        CSV;

    /**
     * An Irish-registered ETF listed in Amsterdam: the listing exchange says NL
     * while the ISIN says IE, which is exactly the disagreement the workbench
     * has to keep showing.
     */
    private const string CROSS_LISTED = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        02-01-2024,09:05,BETA ETF,IE000BETA002,EAM,XAMS,10,264.30,EUR,-2643.00,EUR,-2643.00,EUR,,-2.00,EUR,-2645.00,EUR,bbb-1
        03-06-2025,10:05,BETA ETF,IE000BETA002,EAM,XAMS,-10,300.00,EUR,3000.00,EUR,3000.00,EUR,,-2.00,EUR,2998.00,EUR,bbb-2
        CSV;

    private const string ACCOUNT = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        13-02-2025,06:32,13-02-2025,ALFA CORP,US000ALFA001,Dividend,,USD,82.00,USD,82.00,
        13-02-2025,06:32,13-02-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-12.30,USD,69.70,
        02-01-2025,10:00,02-01-2025,,,iDEAL Deposit,,EUR,1000.00,EUR,1000.00,
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

    public function testTheFilesGuideExplainsWhereToGetTheDegiroFiles(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/skad-wziac-pliki');

        $text = $crawler->filter('section#degiro')->text();
        self::assertStringContainsString('DEGIRO', $text);
        self::assertStringContainsString('Transakcje', $text);
        self::assertStringContainsString('Zestawienie konta', $text);
    }

    public function testTheDegiroSampleFilesImportWithoutErrors(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, [
            'degiro-transakcje.csv' => self::sample('degiro-transakcje.csv'),
            'degiro-rachunek.csv' => self::sample('degiro-rachunek.csv'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.message--error')->count(), $crawler->filter('body')->text());
        self::assertGreaterThan(0, $crawler->filter('[data-trade-ledger] [data-trade] input[name^="trades"][name$="[name]"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name^="dividends"][name$="[name]"]')->count());
    }

    public function testReviewShowsTheProductNameAndTheInferredCountryReadyToEdit(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro-transakcje.csv' => self::TRANSACTIONS]);

        // Two buys and one sell.
        self::assertSame(3, $crawler->filter('[data-trade-ledger] [data-trade] input[name^="trades"][name$="[name]"]')->count());
        self::assertSame('ALFA CORP', $crawler->filter('input[name="trades[0][name]"]')->attr('value'));

        // The country comes from the listing exchange but stays an editable field.
        $country = $crawler->filter('[name="trades[0][country]"]');
        self::assertGreaterThan(0, $country->count());
        self::assertStringContainsString('US', self::selectedCountry($crawler, 'trades[0][country]'));

        self::assertStringContainsString('degiro-transakcje.csv', $crawler->filter('body')->text());
        self::assertStringContainsString('DEGIRO - transakcje giełdowe', $crawler->filter('body')->text());
    }

    /**
     * The proposal note itself is not shown in the web UI. What the workbench guarantees
     * instead is that a proposal is never presented as settled: the country is
     * editable, and any disagreement with the ISIN is a review item.
     */
    public function testTheProposalNoteIsNotRepeatedInTheMessageStrip(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro-transakcje.csv' => self::TRANSACTIONS]);

        self::assertSame(0, $crawler->filter('[data-fragment="messages"] .message--info')->count());
        self::assertStringNotContainsString(
            'ustalony z giełdy',
            $crawler->filter('[data-fragment="messages"]')->text(''),
        );
        self::assertSame('US', self::selectedCountry($crawler, 'trades[0][country]'));
    }

    public function testBuysFromAnEarlierYearBackASaleInTheSettledYear(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro-transakcje.csv' => self::TRANSACTIONS], '2025');

        // Two buy lots from 2024 closed by one sale in 2025.
        $positions = $crawler->filter('#panel-fifo [data-fragment="fifo"] tbody tr');
        self::assertSame(2, $positions->count());
        self::assertSame('2024-04-03', trim($positions->eq(0)->filter('td')->eq(1)->text()));
        self::assertSame('2025-02-27', trim($positions->eq(0)->filter('td')->eq(7)->text()));
        self::assertSame('2024-06-13', trim($positions->eq(1)->filter('td')->eq(1)->text()));
    }

    public function testTheSettledTotalIncludingFeesIsWhatReachesTheReviewScreen(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro-transakcje.csv' => self::TRANSACTIONS], '2025');

        self::assertSame('1523.98', $crawler->filter('input[name="trades[0][total]"]')->attr('value'));
        self::assertSame('2350.99', $crawler->filter('input[name="trades[1][total]"]')->attr('value'));
    }

    public function testFullDegiroFlowProducesAResultInTheChosenCreditVariant(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, [
            'degiro-transakcje.csv' => self::TRANSACTIONS,
            'degiro-rachunek.csv' => self::ACCOUNT,
        ], '2025');

        $crawler = $client->request('POST', '/kalkulator/wynik', $this->reviewPayload($crawler));

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('body')->text();

        self::assertStringContainsString('Przychód', $text);
        self::assertStringContainsString('Koszty uzyskania przychodu', $text);
        self::assertStringNotContainsString('KIS', $crawler->filter('#panel-summary')->text());
        self::assertStringContainsString('nie stanowi porady podatkowej', $text);
    }

    public function testTheCsvReportOfADegiroImportIsDownloadable(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro-rachunek.csv' => self::ACCOUNT], '2025');

        $client->request('POST', '/kalkulator/raport.csv', $this->reviewPayload($crawler));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');

        $csv = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('ALFA CORP', $csv);
    }

    public function testAMisalignedDegiroRowKeepsTheWholeBatchOutOfTheResult(): void
    {
        $client = static::createClient();
        $broken = "Date,Time,Product,ISIN,Reference Exchange,Execution Venue,Quantity,Price,,Value,,"
            ."Transaction and/or third,,Total,,Order ID\n"
            ."03-04-2024,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,100.00\n";

        $crawler = $this->import($client, ['degiro-transakcje.csv' => $broken]);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.message--error')->count());
        self::assertSame(0, $crawler->filter('[data-trade-ledger] [data-trade]')->count());
    }

    public function testNothingFromADegiroUploadIsKeptInTheSession(): void
    {
        $client = static::createClient();
        $this->import($client, ['degiro-transakcje.csv' => self::TRANSACTIONS], '2025');

        $serialized = json_encode($client->getRequest()->getSession()->all());

        self::assertIsString($serialized);
        self::assertStringNotContainsString('ALFA CORP', $serialized);
        self::assertStringNotContainsString('US000ALFA001', $serialized);
    }

    /**
     * An Irish ETF listed in Amsterdam: the listing exchange and the ISIN
     * registration country disagree, and that is a *choice* between two
     * accepted readings - never a finding. The workbench proposes the listing
     * country, keeps the venue code so the choice stays reversible, and says
     * nothing in the attention panel.
     */
    public function testTheListingCountryIsProposedWithoutReportingTheIsinDisagreement(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['cross.csv' => self::CROSS_LISTED]);

        self::assertSame('NL', self::selectedCountry($crawler, 'trades[0][country]'));
        self::assertSame('EAM', $crawler->filter('input[name="trades[0][exchange]"]')->attr('value'));
        self::assertSame(0, $crawler->filter('[data-diagnostic-code="country.exchange_isin_divergence"]')->count());
        self::assertSame(1, $crawler->filter('#panel-attention .attention-empty')->count());

        // Switching the setting re-derives the proposal from the ISIN instead.
        $payload = $this->payload($crawler);
        $payload['country_source'] = 'isin';
        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertSame('IE', self::selectedCountry($crawler, 'trades[0][country]'));
        self::assertSame(0, $crawler->filter('.result-unavailable')->count());

        // And back again, because the venue code round-trips.
        $payload = $this->payload($crawler);
        $payload['country_source'] = 'exchange';
        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertSame('NL', self::selectedCountry($crawler, 'trades[0][country]'));
    }

    /**
     * The 8-share sell consumes a 3-share and a 5-share lot, so the 1,25 sell
     * fee is prorated to 0,47 and 0,78 - the split has to survive that and the
     * totals still have to gross up by the whole 1,25 (5,00 PLN at rate 4,0).
     */
    public function testTheSellFeeIsGrossedIntoRevenueAndAddedToCostAcrossBothLots(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro-transakcje.csv' => self::TRANSACTIONS]);
        $summary = $crawler->filter('#panel-summary')->text();

        // Settled proceeds are 4478,75 -> 17 915,00 PLN; the declared przychód
        // is the kwota należna, 4480,00 -> 17 920,00 PLN.
        self::assertStringContainsString("17\u{00A0}920,00", $summary);
        self::assertStringContainsString("15\u{00A0}504,88", $summary);
        self::assertStringContainsString("2\u{00A0}415,12", $summary);
        self::assertStringContainsString('kosztem odpłatnego zbycia', mb_strtolower($summary));
        self::assertStringNotContainsString("17\u{00A0}915,00", $summary);
    }

    /**
     * The strip renders no import warnings any more, so a reversal - the one
     * import notice the user must act on - has to reach the attention panel.
     * Only messages the importer explicitly tags with a tab are promoted.
     */
    public function testAReversalReachesTheAttentionPanel(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['rachunek.csv' => <<<'CSV'
            Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
            12-12-2025,00:00,14-11-2024,ZETA TRUST,US000ZETA001,Dividend,,USD,-0.40,USD,9.54,
            12-12-2025,00:00,14-11-2024,ZETA TRUST,US000ZETA001,Dividend Tax,,USD,0.06,USD,9.60,
            15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,10.00,
            CSV]);

        $item = $crawler->filter('[data-diagnostic-code="import.review"]');

        self::assertSame(1, $item->count());
        self::assertStringContainsString('ZETA TRUST', $item->text());
        self::assertStringContainsString('2024', $item->text());
        self::assertSame('dividends', $item->filter('[data-attention-target]')->attr('data-attention-target'));
        // A reversal must not block the rest of the statement.
        self::assertSame(0, $crawler->filter('.result-unavailable')->count());
        self::assertSame(1, $crawler->filter('[data-editor-body="dividends"] > tr')->count());
    }

    /** @return array<string, mixed> */
    private function payload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-workbench]')->form()->getPhpValues();
    }

    private static function sample(string $filename): string
    {
        $path = \dirname(__DIR__, 2).'/examples/'.$filename;
        $content = is_file($path) ? file_get_contents($path) : false;

        self::assertIsString($content, $filename.' is missing from examples/');

        return $content;
    }

    private static function selectedCountry(Crawler $crawler, string $name): string
    {
        $field = $crawler->filter(sprintf('[name="%s"]', $name));

        if ('select' === $field->nodeName()) {
            $selected = $field->filter('option[selected]');

            return 0 === $selected->count() ? '' : (string) $selected->attr('value');
        }

        return (string) $field->attr('value');
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
            $path = tempnam(sys_get_temp_dir(), 'pitdegiro');
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

    /**
     * @return array<string, mixed>
     */
    private function reviewPayload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-role="review"]')->form()->getPhpValues();
    }
}

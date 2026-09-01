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

    public function testCalculatorPageExplainsWhereToGetTheDegiroFiles(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('DEGIRO', $text);
        self::assertStringContainsString('Transakcje', $text);
        self::assertStringContainsString('Zestawienie konta', $text);
    }

    public function testCalculatorPageOffersBothDegiroSampleFiles(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator');

        foreach (['degiro-transakcje.csv', 'degiro-rachunek.csv'] as $filename) {
            self::assertGreaterThan(
                0,
                $crawler->filter(sprintf('a[href="/przyklady/%s"]', $filename))->count(),
                $filename.' is not linked',
            );
        }
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
        self::assertGreaterThan(0, $crawler->filter('input[name^="positions"][name$="[name]"]')->count());
        self::assertGreaterThan(0, $crawler->filter('input[name^="dividends"][name$="[name]"]')->count());
    }

    public function testReviewShowsTheProductNameAndTheInferredCountryReadyToEdit(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro-transakcje.csv' => self::TRANSACTIONS]);

        // Two buy lots closed by one sell.
        self::assertSame(2, $crawler->filter('input[name^="positions"][name$="[name]"]')->count());
        self::assertSame('ALFA CORP', $crawler->filter('input[name="positions[0][name]"]')->attr('value'));

        // The country comes from the ISIN prefix but stays an editable field.
        $country = $crawler->filter('[name="positions[0][country]"]');
        self::assertGreaterThan(0, $country->count());
        self::assertStringContainsString('US', self::selectedCountry($crawler, 'positions[0][country]'));

        self::assertStringContainsString('degiro-transakcje.csv', $crawler->filter('body')->text());
        self::assertStringContainsString('DEGIRO - transakcje giełdowe', $crawler->filter('body')->text());
    }

    public function testReviewWarnsThatTheCountryWasOnlyInferredFromTheIsin(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro-transakcje.csv' => self::TRANSACTIONS]);

        self::assertGreaterThan(0, $crawler->filter('.message--warning')->count());
        self::assertMatchesRegularExpression(
            '/ISIN/u',
            $crawler->filter('.message--warning')->text(),
        );
    }

    public function testBuysFromAnEarlierYearBackASaleInTheSettledYear(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro-transakcje.csv' => self::TRANSACTIONS], '2025');

        self::assertSame('2024-04-03', $crawler->filter('input[name="positions[0][buy_date]"]')->attr('value'));
        self::assertSame('2025-02-27', $crawler->filter('input[name="positions[0][sell_date]"]')->attr('value'));
        self::assertSame('2024-06-13', $crawler->filter('input[name="positions[1][buy_date]"]')->attr('value'));
    }

    public function testTheSettledTotalIncludingFeesIsWhatReachesTheReviewScreen(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['degiro-transakcje.csv' => self::TRANSACTIONS], '2025');

        self::assertSame('1523.98', $crawler->filter('input[name="positions[0][buy_amount]"]')->attr('value'));
        self::assertSame('2350.99', $crawler->filter('input[name="positions[1][buy_amount]"]')->attr('value'));
    }

    public function testFullDegiroFlowProducesBothCreditScenarios(): void
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
        self::assertStringContainsString('Dwa warianty', $text);
        self::assertStringContainsString('KIS', $text);
        self::assertStringContainsString('NSA', $text);
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
        self::assertSame(0, $crawler->filter('input[name^="positions["]')->count());
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

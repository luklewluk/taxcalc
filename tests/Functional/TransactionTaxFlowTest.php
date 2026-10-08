<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A transaction tax booked on the account statement reaches the cost of the
 * purchase it was charged on: the buy's Total grows by it, and so does the
 * PIT-38 cost. 1 000 EUR plus 3 EUR at the test rate 4.3 is 4 312,90 zł.
 */
final class TransactionTaxFlowTest extends WebTestCase
{
    private const string TRADES = <<<'CSV'
        Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,Transaction and/or third party costs,,Total,,Order ID
        10-03-2025,09:00,ALFA SA,FR000ALFA001,EPA,XPAR,10,100.0000,EUR,-1000.00,EUR,-1000.00,EUR,,,,-1000.00,EUR,t-8001
        06-11-2025,10:00,ALFA SA,FR000ALFA001,EPA,XPAR,-10,110.0000,EUR,1100.00,EUR,1100.00,EUR,,,,1100.00,EUR,t-8002
        CSV;

    private const string ACCOUNT = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        10-03-2025,09:01,10-03-2025,ALFA SA,FR000ALFA001,French Transaction Tax,,EUR,-3.00,EUR,97.00,ftt-1
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

    public function testTheTaxJoinsTheCostOfItsPurchase(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['transakcje.csv' => self::TRADES, 'konto.csv' => self::ACCOUNT]);

        $buy = null;
        foreach ($crawler->filter('form[data-workbench]')->form()->getPhpValues()['trades'] as $trade) {
            if ('BUY' === $trade['side']) {
                $buy = $trade;
            }
        }
        self::assertNotNull($buy);
        self::assertSame('1003.00', $buy['total']);
        self::assertSame('3.00', $buy['commission']);

        $cost = $crawler->filter('#pit-akcje + .pit-fields > div')->eq(1)->filter('dd')->text();
        self::assertSame('4 312,90 zł', str_replace("\u{a0}", ' ', trim($cost)));
        self::assertSame(0, $crawler->filter('[data-editor-body="fees"] > tr')->count(), 'not an account fee');
    }

    public function testWithoutThePurchaseTheTaxIsReviewedAndNothingIsGuessed(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client, ['konto.csv' => self::ACCOUNT]);

        self::assertStringContainsString('FR000ALFA001', $crawler->filter('#panel-attention')->text());
        self::assertStringContainsString('dolicz tę kwotę do Total zakupu', $crawler->filter('#panel-attention')->text());
    }

    /** @param array<string, string> $files */
    private function import(KernelBrowser $client, array $files): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $uploads = [];
        foreach ($files as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'pittax');
            self::assertIsString($path);
            file_put_contents($path, $content);
            $this->tempFiles[] = $path;
            $uploads[] = new UploadedFile($path, $name, 'text/csv', null, true);
        }

        $crawler = $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value')],
            ['files' => $uploads],
        );
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}

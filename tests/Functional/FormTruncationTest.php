<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * PHP silently drops POST variables past `max_input_vars`. On a tax form that
 * turns into a quietly wrong result, so the number of rows the page sent is
 * declared up front and checked on arrival.
 */
final class FormTruncationTest extends WebTestCase
{
    /** Three payments from a DEGIRO account statement; BBB had nothing withheld. */
    private const string DIVIDENDS = <<<'CSV'
        Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id
        02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend,,USD,100.00,USD,100.00,
        02-04-2025,06:32,02-04-2025,AAA,US000AAAA001,Dividend Tax,,USD,-15.00,USD,85.00,
        02-07-2025,06:32,02-07-2025,BBB,IE000BBBB002,Dividend,,USD,50.00,USD,135.00,
        02-09-2025,06:32,02-09-2025,CCC,US000CCCC003,Dividend,,USD,25.00,USD,160.00,
        02-09-2025,06:32,02-09-2025,CCC,US000CCCC003,Dividend Tax,,USD,-3.75,USD,156.25,
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

    public function testReviewFormDeclaresHowManyRowsItSent(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        self::assertSame('3', $crawler->filter('form[data-role="review"] input[name="expected_dividends"]')->attr('value'));
        self::assertSame('0', $crawler->filter('form[data-role="review"] input[name="expected_trades"]')->attr('value'));
    }

    public function testTruncatedSubmissionIsRefusedInsteadOfSilentlyTaxingFewerRows(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $payload = $crawler->filter('form[data-role="review"]')->form()->getPhpValues();
        self::assertCount(3, $payload['dividends']);

        // Simulate PHP dropping the tail of the POST body.
        unset($payload['dividends'][2]);

        $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $client->getCrawler()->filter('.message--error')->count());

        $text = $client->getCrawler()->filter('body')->text();
        self::assertMatchesRegularExpression('/obci[eę]t|max_input_vars|niekompletn/iu', $text);
        // The wrong number must never be presented as a result.
        self::assertStringNotContainsString('Szacowany podatek', $text);
    }

    public function testCompleteSubmissionStillCalculates(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $payload = $crawler->filter('form[data-role="review"]')->form()->getPhpValues();
        $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Szacowany podatek', $client->getCrawler()->filter('body')->text());
    }

    public function testDeliberatelyRemovedRowsAreNotMistakenForTruncation(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $payload = $crawler->filter('form[data-role="review"]')->form()->getPhpValues();
        // The row is still posted; only its "remove" checkbox is ticked.
        $payload['dividends'][2]['remove'] = '1';

        $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Szacowany podatek', $client->getCrawler()->filter('body')->text());
    }

    public function testCsvReportEndpointRefusesTruncatedInputToo(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $payload = $crawler->filter('form[data-role="review"]')->form()->getPhpValues();
        unset($payload['dividends'][1]);

        $client->request('POST', '/kalkulator/raport.csv', $payload);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertGreaterThan(0, $client->getCrawler()->filter('.message--error')->count());
    }

    public function testResultPageDownloadFormAlsoCarriesTheExpectedCounts(): void
    {
        $client = static::createClient();
        $crawler = $this->import($client);

        $payload = $crawler->filter('form[data-role="review"]')->form()->getPhpValues();
        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertSame('3', $crawler->filter('input[name="expected_dividends"]')->attr('value'));
    }

    private function import(KernelBrowser $client): Crawler
    {
        $crawler = $client->request('GET', '/kalkulator');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $path = tempnam(sys_get_temp_dir(), 'pittrunc');
        self::assertIsString($path);
        file_put_contents($path, self::DIVIDENDS);
        $this->tempFiles[] = $path;

        return $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => $token],
            ['files' => [new UploadedFile($path, 'dywidendy.csv', 'text/csv', null, true)]],
        );
    }
}

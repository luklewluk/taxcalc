<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A written put opened in December 2025 and expired in January 2026: income
 * of 2026, przychód 100 USD × 4.0 = 400 zł (premium plus the writing fee),
 * koszt 1 USD × 4.0 = 4 zł (the fee).
 */
final class OptionsFlowTest extends WebTestCase
{
    private const string PUT = 'AAA 16JAN26 50 P';

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

    public function testAnExpiredWrittenPutIsIncomeOfTheExpiryYear(): void
    {
        $client = static::createClient();
        $crawler = $this->recalculate($client, $this->withOption($this->importSample($client)), '2026');

        self::assertSame(0, $crawler->filter('[data-diagnostic-level="blocking"], .diagnostic--blocking')->count(), $crawler->filter('#panel-attention')->text(''));

        $stock = $crawler->filter('[aria-labelledby="pit-akcje"]');
        self::assertStringContainsString('Akcje, ETF-y i opcje', $stock->text());
        self::assertStringContainsString('400,00 zł', $stock->text());
        self::assertStringContainsString('4,00 zł', $stock->text());
        self::assertStringContainsString('W tym opcje', $stock->text());

        $fifo = $crawler->filter('#panel-fifo')->text();
        self::assertStringContainsString('opcja · krótka', $fifo);
        self::assertStringContainsString('KIS', $fifo);
        self::assertStringContainsString('2026-01-15', $fifo, 'The premium is converted at the rate before the expiry.');
    }

    public function testTheOptionIsNotIncomeOfTheYearItWasWritten(): void
    {
        $client = static::createClient();
        $crawler = $this->recalculate($client, $this->withOption($this->importSample($client)), '2025');

        self::assertStringNotContainsString(self::PUT, $crawler->filter('#panel-fifo')->text());
    }

    public function testTheOptionRowsSurviveTheRenderedForm(): void
    {
        $client = static::createClient();
        $crawler = $this->recalculate($client, $this->withOption($this->importSample($client)), '2026');

        $payload = $crawler->filter('form[data-workbench]')->form()->getPhpValues();
        $options = array_values(array_filter($payload['trades'], static fn (array $row): bool => self::PUT === $row['symbol']));

        self::assertSame(['OPT', 'OPT'], array_column($options, 'asset'));
        self::assertSame(['open', 'close'], array_column($options, 'effect'));

        // The hidden compatibility echo has no kind or direction, so an option
        // must not appear in it.
        self::assertSame(0, $crawler->filter('input[name^="positions"][value="'.self::PUT.'"]')->count());
    }

    public function testAnOptionRowThatDoesNotSayWhatItDoesBlocksTheResult(): void
    {
        $client = static::createClient();
        $payload = $this->withOption($this->importSample($client));
        $last = array_key_last($payload['trades']);
        $payload['trades'][$last]['effect'] = '';

        $crawler = $this->recalculate($client, $payload, '2026');

        self::assertStringContainsString('otwiera, czy zamyka', $crawler->filter('#panel-attention')->text());
    }

    /**
     * @return array<string, mixed>
     */
    private function importSample(KernelBrowser $client): array
    {
        $crawler = $client->request('GET', '/kalkulator');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $path = tempnam(sys_get_temp_dir(), 'pitopt');
        self::assertIsString($path);
        file_put_contents($path, (string) file_get_contents(dirname(__DIR__, 2).'/examples/ibkr-activity-statement.csv'));
        $this->tempFiles[] = $path;

        $crawler = $client->request(
            'POST',
            '/kalkulator/import',
            ['tax_year' => '2025', '_token' => $token],
            ['files' => [new UploadedFile($path, 'statement.csv', 'text/csv', null, true)]],
        );

        return $crawler->filter('form[data-workbench]')->form()->getPhpValues();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withOption(array $payload): array
    {
        foreach ([['2025-12-15', 'SELL', '99', '1', 'open'], ['2026-01-16', 'BUY', '0', '0', 'close']] as [$date, $side, $total, $fee, $effect]) {
            $payload['trades'][] = [
                'id' => '', 'broker' => 'IBKR', 'pool' => self::PUT.'@USD', 'symbol' => self::PUT, 'name' => self::PUT,
                'country' => 'US', 'exchange' => 'XCBO', 'asset' => 'OPT', 'effect' => $effect,
                'date' => $date, 'time' => '10:00:00', 'side' => $side, 'quantity' => '1', 'currency' => 'USD',
                'total' => $total, 'unit_price' => '', 'price_currency' => '', 'commission' => $fee, 'autofx' => '',
                'external_id' => '', 'source' => 'ręcznie',
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recalculate(KernelBrowser $client, array $payload, string $year): Crawler
    {
        $payload['tax_year'] = $year;

        return $client->request('POST', '/kalkulator/wynik', $payload);
    }
}

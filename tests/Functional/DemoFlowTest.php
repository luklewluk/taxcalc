<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Symulacja": the workbench on the fictional statements in demo/, reached
 * without uploading anything. It goes through the real import, so it has to
 * stay a clean, complete result - a demo that opens on a blocking item or an
 * error would show the product at its worst.
 */
final class DemoFlowTest extends WebTestCase
{
    public function testTheDemoOpensACompleteResultWithoutUploadingAnything(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/kalkulator/symulacja');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[data-workbench]')->count());
        self::assertStringContainsString('Symulacja na fikcyjnych danych', $crawler->filter('[data-role="demo-notice"]')->text());
        self::assertSame('/kalkulator', $crawler->filter('[data-role="demo-notice"] a')->attr('href'));

        self::assertSame(0, $crawler->filter('.message--error')->count(), $crawler->filter('body')->text());
        self::assertSame('Wymaga uwagi 0', trim($crawler->filter('#tab-attention')->text()));
        self::assertSame('true', $crawler->filter('#tab-summary')->attr('aria-selected'));
        self::assertSame('2025', $crawler->filter('select[name="tax_year"] option[selected]')->attr('value'));

        $summary = $crawler->filter('#panel-summary')->text();
        self::assertStringContainsString('Przychód', $summary);
        self::assertStringContainsString('PIT/ZG', $summary);
        self::assertStringNotContainsString('zachowawczy', $crawler->filter('#panel-dividends')->text());
    }

    public function testTheDemoCoversBothBrokersEveryCurrencyAndEveryKindOfOption(): void
    {
        $client = static::createClient();
        $payload = $this->payload($client->request('GET', '/kalkulator/symulacja'));

        /** @var list<array<string, string>> $trades */
        $trades = array_values($payload['trades']);
        self::assertSame(['DEGIRO', 'IBKR'], self::distinct(array_column($trades, 'broker')));
        self::assertSame(['CAD', 'CHF', 'EUR', 'GBP', 'PLN', 'USD'], self::distinct(array_column($trades, 'currency')));

        $options = array_values(array_filter($trades, static fn (array $row): bool => 'OPT' === $row['asset']));
        $opened = array_filter($options, static fn (array $row): bool => 'open' === $row['effect']);
        $kinds = array_map(
            static fn (array $row): string => substr(trim($row['name']), -1).' '.$row['side'],
            $opened,
        );
        sort($kinds);
        self::assertSame(['C BUY', 'C SELL', 'P BUY', 'P SELL'], $kinds, 'a call and a put, each bought and written');

        self::assertGreaterThanOrEqual(4, count($payload['dividends']));
        self::assertNotEmpty($payload['fees']);
    }

    public function testTheNoticeSurvivesARecalculation(): void
    {
        $client = static::createClient();
        $payload = $this->payload($client->request('GET', '/kalkulator/symulacja'));

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-role="demo-notice"]')->count());
    }

    public function testAnOrdinaryWorkbenchSaysNothingAboutASimulation(): void
    {
        $client = static::createClient();
        $payload = $this->payload($client->request('GET', '/kalkulator/symulacja'));
        unset($payload['demo']);

        $crawler = $client->request('POST', '/kalkulator/wynik', $payload);

        self::assertSame(0, $crawler->filter('[data-role="demo-notice"]')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Crawler $crawler): array
    {
        return $crawler->filter('form[data-workbench]')->form()->getPhpValues();
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private static function distinct(array $values): array
    {
        $unique = array_values(array_unique(array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $values)));
        sort($unique);

        return $unique;
    }
}

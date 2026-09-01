<?php

declare(strict_types=1);

namespace App\Tests\Unit\CurrencyRate;

use App\CurrencyRate\NbpApiRateProvider;
use App\Exception\ExchangeRateUnavailableException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Uses {@see MockHttpClient} exclusively - no request ever leaves the process.
 */
#[CoversClass(NbpApiRateProvider::class)]
final class NbpApiRateProviderTest extends TestCase
{
    public function testAsksForTheDayBeforeTheTransactionDate(): void
    {
        $requestedUrls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = $url;

            return new MockResponse(
                json_encode([
                    'code' => 'USD',
                    'rates' => [['no' => '050/A/NBP/2025', 'effectiveDate' => '2025-03-13', 'mid' => 3.9412]],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            );
        }, 'https://api.nbp.pl/api/');

        $rate = (new NbpApiRateProvider($client))
            ->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));

        self::assertSame('3.9412', (string) $rate->rate);
        self::assertSame('2025-03-13', $rate->date->format('Y-m-d'));
        self::assertSame('050/A/NBP/2025', $rate->table);
        self::assertCount(1, $requestedUrls);
        self::assertStringContainsString('exchangerates/rates/a/usd/2025-03-13', $requestedUrls[0]);
    }

    public function testWalksBackOverWeekendsAndHolidays(): void
    {
        $requestedUrls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = $url;

            if (str_contains($url, '2025-03-14')) {
                return new MockResponse('404 NotFound', ['http_code' => 404]);
            }

            return new MockResponse(
                json_encode([
                    'code' => 'USD',
                    'rates' => [['no' => '049/A/NBP/2025', 'effectiveDate' => '2025-03-13', 'mid' => 3.94]],
                ], JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            );
        }, 'https://api.nbp.pl/api/');

        // Saturday 2025-03-15 -> D-1 is Friday 14th (mocked as missing) -> 13th
        $rate = (new NbpApiRateProvider($client))
            ->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-15'));

        self::assertSame('3.94', (string) $rate->rate);
        self::assertSame('2025-03-13', $rate->date->format('Y-m-d'));
        self::assertCount(2, $requestedUrls);
    }

    public function testGivesUpAfterTheLookbackWindowInsteadOfLoopingForever(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('404 NotFound', ['http_code' => 404]);
        }, 'https://api.nbp.pl/api/');

        $this->expectException(ExchangeRateUnavailableException::class);

        try {
            (new NbpApiRateProvider($client))->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));
        } finally {
            self::assertLessThanOrEqual(15, $calls);
            self::assertGreaterThan(1, $calls);
        }
    }

    public function testRejectsMalformedPayload(): void
    {
        $client = new MockHttpClient(
            new MockResponse('{"unexpected": true}', ['http_code' => 200]),
            'https://api.nbp.pl/api/',
        );

        $this->expectException(ExchangeRateUnavailableException::class);

        (new NbpApiRateProvider($client))->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));
    }

    public function testRejectsNonNumericAndNonPositiveRatesAsDomainErrors(): void
    {
        foreach (['oops', 0, -4] as $mid) {
            $client = new MockHttpClient(new MockResponse(json_encode([
                'rates' => [['no' => 'x', 'effectiveDate' => '2025-03-13', 'mid' => $mid]],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]), 'https://api.nbp.pl/api/');

            try {
                (new NbpApiRateProvider($client))->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));
                self::fail('Invalid rate was accepted: '.var_export($mid, true));
            } catch (ExchangeRateUnavailableException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsInvalidOrMismatchedEffectiveDate(): void
    {
        foreach (['2025-02-31', '2025-03-12'] as $effectiveDate) {
            $client = new MockHttpClient(new MockResponse(json_encode([
                'rates' => [['no' => 'x', 'effectiveDate' => $effectiveDate, 'mid' => 4.0]],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]), 'https://api.nbp.pl/api/');

            try {
                (new NbpApiRateProvider($client))->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));
                self::fail('Invalid effective date was accepted: '.$effectiveDate);
            } catch (ExchangeRateUnavailableException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsPayloadForADifferentCurrency(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'code' => 'EUR',
            'rates' => [['no' => 'x', 'effectiveDate' => '2025-03-13', 'mid' => 4.5]],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]), 'https://api.nbp.pl/api/');

        $this->expectException(ExchangeRateUnavailableException::class);
        (new NbpApiRateProvider($client))->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));
    }

    public function testRejectsCurrencyCodesThatCouldEscapeTheUrlPath(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 200]), 'https://api.nbp.pl/api/');

        $this->expectException(ExchangeRateUnavailableException::class);

        (new NbpApiRateProvider($client))->rateForPreviousBusinessDay('../../a', new DateTimeImmutable('2025-03-14'));
    }

    public function testTransportFailureBecomesDomainException(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('network down');
        }, 'https://api.nbp.pl/api/');

        $this->expectException(ExchangeRateUnavailableException::class);

        (new NbpApiRateProvider($client))->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));
    }
}

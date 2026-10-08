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

    /**
     * The Christmas 2025 run: NBP published on the 23rd and then not again
     * until the 29th - Wigilia became a public holiday in 2025, the 25th and
     * 26th are holidays and the 27th/28th a weekend. A payment settled on the
     * 24th therefore legitimately carries the rate of the 23rd, five days
     * earlier. Nothing in the suite walked back more than one day before this.
     */
    public function testWalksBackOverAMultiDayHolidayRun(): void
    {
        $requestedUrls = [];
        $published = ['2025-12-23' => ['248/A/NBP/2025', 3.5848]];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls, $published): MockResponse {
            $requestedUrls[] = $url;

            foreach ($published as $day => [$no, $mid]) {
                if (str_contains($url, $day)) {
                    return new MockResponse(
                        json_encode([
                            'code' => 'USD',
                            'rates' => [['no' => $no, 'effectiveDate' => $day, 'mid' => $mid]],
                        ], JSON_THROW_ON_ERROR),
                        ['http_code' => 200],
                    );
                }
            }

            return new MockResponse('404 NotFound', ['http_code' => 404]);
        }, 'https://api.nbp.pl/api/');

        $provider = new NbpApiRateProvider($client);

        // Settled on the 24th: D-1 is the 23rd, one request, no walk.
        $rate = $provider->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-12-24'));
        self::assertSame('3.5848', (string) $rate->rate);
        self::assertSame('2025-12-23', $rate->date->format('Y-m-d'));
        self::assertSame('248/A/NBP/2025', $rate->table);
        self::assertCount(1, $requestedUrls);

        // Settled on the 29th: D-1 is the 28th, and the walk has to cross the
        // 28th, 27th, 26th, 25th and 24th before the 23rd answers.
        $requestedUrls = [];
        $rate = $provider->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-12-29'));
        self::assertSame('2025-12-23', $rate->date->format('Y-m-d'));
        self::assertCount(6, $requestedUrls);
        self::assertStringContainsString('2025-12-28', $requestedUrls[0]);
        self::assertStringContainsString('2025-12-23', $requestedUrls[5]);
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

    /**
     * Table A quotes JPY, HUF, KRW, CLP and ISK per unit with six decimals and
     * IDR with eight. Formatting the JSON float to four places turned the yen's
     * 0.026287 into 0.0263 - a 0.05% error on every yen amount.
     */
    public function testKeepsEveryPublishedDecimalOfSmallUnitCurrencies(): void
    {
        foreach (['JPY' => [0.026287, '0.026287'], 'IDR' => [0.00025453, '0.00025453'], 'USD' => [4.1219, '4.1219']] as $code => [$mid, $expected]) {
            $client = new MockHttpClient(new MockResponse(json_encode([
                'code' => $code,
                'rates' => [['no' => '001/A/NBP/2025', 'effectiveDate' => '2025-01-02', 'mid' => $mid]],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]), 'https://api.nbp.pl/api/');

            $rate = (new NbpApiRateProvider($client))->rateForPreviousBusinessDay($code, new DateTimeImmutable('2025-01-03'));

            self::assertSame($expected, (string) $rate->rate, $code);
        }
    }

    public function testReadsEveryCurrencyOfEveryTableInARange(): void
    {
        $requestedUrls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requestedUrls): MockResponse {
            $requestedUrls[] = $url;

            return new MockResponse(json_encode([
                self::table('001/A/NBP/2025', '2025-01-02', ['USD' => 4.1219, 'JPY' => 0.026287]),
                self::table('002/A/NBP/2025', '2025-01-03', ['USD' => 4.1512, 'JPY' => 0.026411]),
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        }, 'https://api.nbp.pl/api/');

        $rates = (new NbpApiRateProvider($client))
            ->tablesBetween(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-05'));

        self::assertCount(1, $requestedUrls);
        self::assertStringContainsString('exchangerates/tables/a/2025-01-01/2025-01-05/', $requestedUrls[0]);
        self::assertSame(
            [
                'USD 2025-01-02 4.1219 001/A/NBP/2025',
                'JPY 2025-01-02 0.026287 001/A/NBP/2025',
                'USD 2025-01-03 4.1512 002/A/NBP/2025',
                'JPY 2025-01-03 0.026411 002/A/NBP/2025',
            ],
            array_map(
                static fn ($rate): string => sprintf('%s %s %s %s', $rate->currency, $rate->date->format('Y-m-d'), $rate->rate, $rate->table),
                $rates,
            ),
        );
    }

    /**
     * NBP answers 404 when the range holds no table at all - New Year's Day on
     * its own, or a weekend. That is an answer, not a failure.
     */
    public function testARangeWithoutAnyTableIsEmpty(): void
    {
        $client = new MockHttpClient(new MockResponse('404 NotFound - Not Found - Brak danych', ['http_code' => 404]), 'https://api.nbp.pl/api/');

        self::assertSame([], (new NbpApiRateProvider($client))
            ->tablesBetween(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-01')));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedRanges(): iterable
    {
        yield 'not a list' => [['table' => 'A']];
        yield 'table B' => [[['table' => 'B'] + self::table('001/B/NBP/2025', '2025-01-02', ['USD' => 4.1])]];
        yield 'date outside the range' => [[self::table('x', '2024-12-31', ['USD' => 4.1])]];
        yield 'impossible date' => [[self::table('x', '2025-01-32', ['USD' => 4.1])]];
        yield 'the same day twice' => [[self::table('x', '2025-01-02', ['USD' => 4.1]), self::table('y', '2025-01-02', ['USD' => 4.2])]];
        yield 'the same currency twice' => [[['table' => 'A', 'no' => 'x', 'effectiveDate' => '2025-01-02', 'rates' => [
            ['code' => 'USD', 'mid' => 4.1], ['code' => 'USD', 'mid' => 4.2],
        ]]]];
        yield 'bad code' => [[self::table('x', '2025-01-02', ['US' => 4.1])]];
        yield 'zero rate' => [[self::table('x', '2025-01-02', ['USD' => 0])]];
        yield 'text rate' => [[self::table('x', '2025-01-02', ['USD' => 'oops'])]];
        yield 'no rates' => [[['table' => 'A', 'no' => 'x', 'effectiveDate' => '2025-01-02', 'rates' => []]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedRanges')]
    public function testRejectsAMalformedTableRange(mixed $payload): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => 200]), 'https://api.nbp.pl/api/');

        $this->expectException(ExchangeRateUnavailableException::class);

        (new NbpApiRateProvider($client))->tablesBetween(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-05'));
    }

    public function testATableRangeTransportFailureBecomesDomainException(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('network down');
        }, 'https://api.nbp.pl/api/');

        $this->expectException(ExchangeRateUnavailableException::class);

        (new NbpApiRateProvider($client))->tablesBetween(new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-05'));
    }

    public function testRefusesARangeNbpWouldReject(): void
    {
        $provider = new NbpApiRateProvider(new MockHttpClient([], 'https://api.nbp.pl/api/'));

        foreach ([['2025-01-05', '2025-01-01'], ['2025-01-01', '2025-04-04']] as [$from, $to]) {
            try {
                $provider->tablesBetween(new DateTimeImmutable($from), new DateTimeImmutable($to));
                self::fail(sprintf('Range %s..%s was accepted.', $from, $to));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTransportFailureBecomesDomainException(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('network down');
        }, 'https://api.nbp.pl/api/');

        $this->expectException(ExchangeRateUnavailableException::class);

        (new NbpApiRateProvider($client))->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));
    }

    /**
     * @param array<string, mixed> $mids
     *
     * @return array<string, mixed>
     */
    private static function table(string $no, string $effectiveDate, array $mids): array
    {
        $rates = [];
        foreach ($mids as $code => $mid) {
            $rates[] = ['currency' => 'waluta', 'code' => $code, 'mid' => $mid];
        }

        return ['table' => 'A', 'no' => $no, 'effectiveDate' => $effectiveDate, 'rates' => $rates];
    }
}

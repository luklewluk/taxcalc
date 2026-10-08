<?php

declare(strict_types=1);

namespace App\Tests\Functional\CurrencyRate;

use App\CurrencyRate\DatabaseNbpRateProvider;
use App\CurrencyRate\Entity\NbpCoverage;
use App\CurrencyRate\Entity\NbpTableRate;
use App\CurrencyRate\NbpApiRateProvider;
use App\CurrencyRate\Repository\NbpCoverageRepository;
use App\CurrencyRate\Repository\NbpTableRateRepository;
use App\Exception\ExchangeRateUnavailableException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Runs against the in-memory SQLite database of the test environment, with a
 * schema built from the mapping, and a {@see MockHttpClient} standing in for
 * NBP - nothing leaves the process. Every kernel boot is a fresh database.
 */
#[CoversClass(DatabaseNbpRateProvider::class)]
final class DatabaseNbpRateProviderTest extends KernelTestCase
{
    /**
     * Days NBP published nothing although they fall on a weekday.
     */
    private const array HOLIDAYS = ['2024-12-24', '2024-12-25', '2024-12-26', '2025-01-01', '2025-01-06'];

    /**
     * @var list<string>
     */
    private array $requests = [];

    private MockClock $clock;

    private ManagerRegistry $registry;

    protected function setUp(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $this->registry = $registry;
        $this->clock = new MockClock('2025-10-08 10:00:00');
    }

    public function testAQuarterIsFetchedOnceAndOutlivesTheProcess(): void
    {
        $this->createSchema();
        $provider = $this->provider();

        $rate = $provider->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));

        self::assertSame('4.0313', (string) $rate->rate);
        self::assertSame('2025-03-13', $rate->date->format('Y-m-d'));
        self::assertSame('2025-03-13/A/NBP', $rate->table);
        self::assertSame(['exchangerates/tables/a/2025-01-01/2025-03-31/'], $this->requests);

        // Every currency of the quarter came with that one request.
        $rate = $provider->rateForPreviousBusinessDay('JPY', new DateTimeImmutable('2025-02-12'));
        self::assertSame('0.026211', (string) $rate->rate);
        self::assertCount(1, $this->requests);

        // A new process after a deploy: nothing in memory, the database remains.
        $this->entityManager()->clear();
        $rate = $this->provider()->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));
        self::assertSame('4.0313', (string) $rate->rate);
        self::assertCount(1, $this->requests);
    }

    public function testMondayCarriesFridaysRate(): void
    {
        $this->createSchema();

        $rate = $this->provider()->rateForPreviousBusinessDay('EUR', new DateTimeImmutable('2025-03-17'));

        self::assertSame('2025-03-14', $rate->date->format('Y-m-d'));
        self::assertSame('4.3314', (string) $rate->rate);
    }

    /**
     * 2 January: D-1 is New Year's Day, so the rate is the one of 31 December -
     * in the previous quarter, which is fetched as well.
     */
    public function testAWindowReachingIntoThePreviousQuarterFetchesBoth(): void
    {
        $this->createSchema();

        $rate = $this->provider()->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-01-02'));

        self::assertSame('2024-12-31', $rate->date->format('Y-m-d'));
        self::assertSame(
            ['exchangerates/tables/a/2024-10-01/2024-12-31/', 'exchangerates/tables/a/2025-01-01/2025-03-31/'],
            $this->requests,
        );
    }

    public function testTheCurrentQuarterIsStoredUpToYesterdayAndExtendedLater(): void
    {
        $this->createSchema();

        $rate = $this->provider()->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-10-08'));
        self::assertSame('2025-10-07', $rate->date->format('Y-m-d'));
        self::assertSame(
            ['exchangerates/tables/a/2025-07-01/2025-09-30/', 'exchangerates/tables/a/2025-10-01/2025-10-07/'],
            $this->requests,
        );

        $this->requests = [];
        $this->clock->modify('+2 days');
        $rate = $this->provider()->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-10-10'));

        self::assertSame('2025-10-09', $rate->date->format('Y-m-d'));
        self::assertSame(['exchangerates/tables/a/2025-10-08/2025-10-09/'], $this->requests);
    }

    public function testACurrencyOutsideTableAIsUnavailable(): void
    {
        $this->createSchema();

        $this->expectException(ExchangeRateUnavailableException::class);

        $this->provider()->rateForPreviousBusinessDay('XYZ', new DateTimeImmutable('2025-03-14'));
    }

    /**
     * Today's table may not be out yet, so a rate from today on is read from
     * NBP directly and not stored.
     */
    public function testARateThatIsNotFinalYetGoesStraightToNbp(): void
    {
        $this->createSchema();

        $rate = $this->provider()->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-10-09'));

        self::assertSame('2025-10-08', $rate->date->format('Y-m-d'));
        self::assertSame(['exchangerates/rates/a/usd/2025-10-08/'], $this->requests);
        self::assertSame(0, $this->entityManager()->getRepository(NbpTableRate::class)->count());
    }

    /**
     * The rates are public NBP data: a database that is down or was never
     * migrated costs speed, never the result.
     */
    public function testADatabaseWithoutTheSchemaFallsBackToNbp(): void
    {
        $provider = $this->provider();

        $rate = $provider->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));
        self::assertSame('2025-03-13', $rate->date->format('Y-m-d'));

        $rate = $provider->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-17'));
        self::assertSame('2025-03-14', $rate->date->format('Y-m-d'));

        self::assertSame(
            ['exchangerates/rates/a/usd/2025-03-13/', 'exchangerates/rates/a/usd/2025-03-16/', 'exchangerates/rates/a/usd/2025-03-15/', 'exchangerates/rates/a/usd/2025-03-14/'],
            $this->requests,
        );
    }

    /**
     * Two requests filling the same quarter at once: the one that loses the
     * race hits the unique key, starts over with a fresh entity manager and
     * finds the other one's rows.
     */
    public function testLosingAWriteRaceIsNotAnError(): void
    {
        $this->createSchema();
        $rival = new class {
            public bool $raced = false;

            public function preFlush(PreFlushEventArgs $event): void
            {
                if ($this->raced) {
                    return;
                }
                $this->raced = true;
                $event->getObjectManager()->getConnection()->insert('nbp_coverage', ['quarter' => '2025-Q1', 'covered_through' => '2025-03-31']);
            }
        };
        $this->entityManager()->getEventManager()->addEventListener(Events::preFlush, $rival);

        $rate = $this->provider()->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));

        self::assertTrue($rival->raced);
        self::assertSame('2025-03-13', $rate->date->format('Y-m-d'));
        $coverage = $this->entityManager()->find(NbpCoverage::class, '2025-Q1');
        self::assertNotNull($coverage);
        self::assertSame('2025-03-31', $coverage->coveredThrough()->format('Y-m-d'));
    }

    private function provider(): DatabaseNbpRateProvider
    {
        $client = new MockHttpClient(fn (string $method, string $url): MockResponse => $this->respond($url), 'https://api.nbp.pl/api/');

        return new DatabaseNbpRateProvider(
            new NbpApiRateProvider($client),
            new NbpTableRateRepository($this->registry),
            new NbpCoverageRepository($this->registry),
            $this->registry,
            $this->clock,
            new NullLogger(),
        );
    }

    /**
     * A fake NBP: one table per weekday that is not a holiday, with rates
     * derived from the day so every day is distinguishable.
     */
    private function respond(string $url): MockResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->requests[] = substr($path, strlen('/api/'));

        if (1 === preg_match('#exchangerates/tables/a/(\d{4}-\d{2}-\d{2})/(\d{4}-\d{2}-\d{2})/$#', $path, $range)) {
            $tables = [];
            for ($day = new DateTimeImmutable($range[1]); $day <= new DateTimeImmutable($range[2]); $day = $day->modify('+1 day')) {
                if ($this->published($day)) {
                    $tables[] = [
                        'table' => 'A',
                        'no' => $day->format('Y-m-d').'/A/NBP',
                        'effectiveDate' => $day->format('Y-m-d'),
                        'rates' => [
                            ['currency' => 'dolar amerykański', 'code' => 'USD', 'mid' => self::mid('USD', $day)],
                            ['currency' => 'euro', 'code' => 'EUR', 'mid' => self::mid('EUR', $day)],
                            ['currency' => 'jen (Japonia)', 'code' => 'JPY', 'mid' => self::mid('JPY', $day)],
                        ],
                    ];
                }
            }

            return [] === $tables
                ? new MockResponse('404 NotFound - Not Found - Brak danych', ['http_code' => 404])
                : new MockResponse(json_encode($tables, JSON_THROW_ON_ERROR), ['http_code' => 200]);
        }

        if (1 === preg_match('#exchangerates/rates/a/usd/(\d{4}-\d{2}-\d{2})/$#', $path, $single)) {
            $day = new DateTimeImmutable($single[1]);

            return $this->published($day)
                ? new MockResponse(json_encode([
                    'code' => 'USD',
                    'rates' => [['no' => $single[1].'/A/NBP', 'effectiveDate' => $single[1], 'mid' => self::mid('USD', $day)]],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200])
                : new MockResponse('404 NotFound', ['http_code' => 404]);
        }

        return new MockResponse('400 BadRequest', ['http_code' => 400]);
    }

    private function published(DateTimeImmutable $day): bool
    {
        return (int) $day->format('N') <= 5 && !in_array($day->format('Y-m-d'), self::HOLIDAYS, true);
    }

    /**
     * USD on 13 March is 4.0313, EUR 4.3313, JPY 0.026213.
     */
    private static function mid(string $code, DateTimeImmutable $day): float
    {
        $monthDay = $day->format('md');

        return (float) match ($code) {
            'USD' => '4.'.$monthDay,
            'EUR' => '4.3'.substr($monthDay, 1),
            default => '0.0262'.substr($monthDay, 2),
        };
    }

    private function createSchema(): void
    {
        $em = $this->entityManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->registry->getManagerForClass(NbpTableRate::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}

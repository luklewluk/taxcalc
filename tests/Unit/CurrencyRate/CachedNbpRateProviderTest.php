<?php

declare(strict_types=1);

namespace App\Tests\Unit\CurrencyRate;

use App\CurrencyRate\CachedNbpRateProvider;
use App\CurrencyRate\NbpRate;
use App\CurrencyRate\NbpRateProviderInterface;
use App\Money\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(CachedNbpRateProvider::class)]
final class CachedNbpRateProviderTest extends TestCase
{
    public function testTheSameCurrencyAndDateIsFetchedOnlyOnce(): void
    {
        $delegate = $this->createMock(NbpRateProviderInterface::class);
        $delegate->expects(self::once())
            ->method('rateForPreviousBusinessDay')
            ->willReturn(new NbpRate('USD', Decimal::of('3.9412'), new DateTimeImmutable('2025-03-13'), '050/A/NBP/2025'));

        $provider = new CachedNbpRateProvider($delegate, new ArrayAdapter());

        $first = $provider->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14'));
        $second = $provider->rateForPreviousBusinessDay('USD', new DateTimeImmutable('2025-03-14 23:59:59'));

        self::assertSame('3.9412', (string) $first->rate);
        self::assertSame('3.9412', (string) $second->rate);
        self::assertSame('2025-03-13', $second->date->format('Y-m-d'));
        self::assertSame('050/A/NBP/2025', $second->table);
    }

    public function testDifferentCurrenciesUseDifferentCacheKeys(): void
    {
        $delegate = $this->createMock(NbpRateProviderInterface::class);
        $delegate->expects(self::exactly(2))
            ->method('rateForPreviousBusinessDay')
            ->willReturnCallback(static fn (string $currency) => new NbpRate(
                $currency,
                Decimal::of('USD' === $currency ? '3.9412' : '4.3000'),
                new DateTimeImmutable('2025-03-13'),
                null,
            ));

        $provider = new CachedNbpRateProvider($delegate, new ArrayAdapter());
        $date = new DateTimeImmutable('2025-03-14');

        self::assertSame('3.9412', (string) $provider->rateForPreviousBusinessDay('USD', $date)->rate);
        self::assertSame('4.3000', (string) $provider->rateForPreviousBusinessDay('EUR', $date)->rate);
    }
}

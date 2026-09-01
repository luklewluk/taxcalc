<?php

declare(strict_types=1);

namespace App\Tests\Unit\CurrencyRate;

use App\CurrencyRate\NbpExchange;
use App\CurrencyRate\NbpRate;
use App\CurrencyRate\NbpRateProviderInterface;
use App\Exception\ExchangeRateUnavailableException;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NbpExchange::class)]
final class NbpExchangeTest extends TestCase
{
    public function testPolishZlotyIsPassedThroughWithRateOneAndNoLookup(): void
    {
        $provider = $this->createMock(NbpRateProviderInterface::class);
        $provider->expects(self::never())->method('rateForPreviousBusinessDay');

        $exchanged = (new NbpExchange($provider))
            ->toPln(Amount::of('1234.56', 'PLN'), new DateTimeImmutable('2025-03-14'));

        self::assertSame('1234.56', (string) $exchanged->pln->value());
        self::assertSame('PLN', $exchanged->pln->currency());
        self::assertSame('1', (string) $exchanged->rate);
        self::assertNull($exchanged->rateDate);
    }

    public function testForeignCurrencyIsConvertedWithTheDayBeforeRate(): void
    {
        $provider = $this->createMock(NbpRateProviderInterface::class);
        $provider->expects(self::once())
            ->method('rateForPreviousBusinessDay')
            ->with('USD', self::callback(
                static fn (DateTimeImmutable $d) => '2025-03-14' === $d->format('Y-m-d')
            ))
            ->willReturn(new NbpRate('USD', Decimal::of('3.9412'), new DateTimeImmutable('2025-03-13'), '050/A/NBP/2025'));

        $exchanged = (new NbpExchange($provider))
            ->toPln(Amount::of('100.00', 'USD'), new DateTimeImmutable('2025-03-14'));

        self::assertSame('100.00', (string) $exchanged->original->value());
        // 100.00 * 3.9412 = 394.12
        self::assertSame('394.12', (string) $exchanged->pln->value());
        self::assertSame('3.9412', (string) $exchanged->rate);
        self::assertNotNull($exchanged->rateDate);
        self::assertSame('2025-03-13', $exchanged->rateDate->format('Y-m-d'));
        self::assertSame('050/A/NBP/2025', $exchanged->rateTable);
    }

    public function testResultIsRoundedToGroszeHalfUp(): void
    {
        $provider = $this->createStub(NbpRateProviderInterface::class);
        $provider->method('rateForPreviousBusinessDay')
            ->willReturn(new NbpRate('USD', Decimal::of('4.1234'), new DateTimeImmutable('2025-03-13'), null));

        // 0.2025 * 4.1234 = 0.834988...  ->  0.83
        $exchanged = (new NbpExchange($provider))
            ->toPln(Amount::of('0.2025', 'USD'), new DateTimeImmutable('2025-03-14'));

        self::assertSame('0.83', (string) $exchanged->pln->value());
    }

    public function testUnavailableRatePropagatesAsDomainException(): void
    {
        $provider = $this->createStub(NbpRateProviderInterface::class);
        $provider->method('rateForPreviousBusinessDay')
            ->willThrowException(ExchangeRateUnavailableException::forCurrency('XYZ', new DateTimeImmutable('2025-03-14')));

        $this->expectException(ExchangeRateUnavailableException::class);

        (new NbpExchange($provider))->toPln(Amount::of('1.00', 'XYZ'), new DateTimeImmutable('2025-03-14'));
    }

    public function testZeroAmountStillReportsTheRate(): void
    {
        $provider = $this->createStub(NbpRateProviderInterface::class);
        $provider->method('rateForPreviousBusinessDay')
            ->willReturn(new NbpRate('EUR', Decimal::of('4.3000'), new DateTimeImmutable('2025-03-13'), null));

        $exchanged = (new NbpExchange($provider))
            ->toPln(Amount::zero('EUR'), new DateTimeImmutable('2025-03-14'));

        self::assertSame('0.00', (string) $exchanged->pln->value());
        self::assertSame('4.3000', (string) $exchanged->rate);
    }
}

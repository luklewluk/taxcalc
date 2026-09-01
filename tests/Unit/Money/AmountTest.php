<?php

declare(strict_types=1);

namespace App\Tests\Unit\Money;

use App\Exception\CurrencyMismatchException;
use App\Exception\InvalidCurrencyException;
use App\Money\Amount;
use App\Money\Decimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Amount::class)]
final class AmountTest extends TestCase
{
    public function testKeepsFullPrecisionOfSubCentValues(): void
    {
        $amount = Amount::of('0.2025', 'USD');

        self::assertSame('0.2025', (string) $amount->value());
        self::assertSame('USD', $amount->currency());
        self::assertSame('0.2025 USD', (string) $amount);
    }

    #[DataProvider('invalidCurrencies')]
    public function testRejectsInvalidCurrencyCodes(string $currency): void
    {
        $this->expectException(InvalidCurrencyException::class);

        Amount::of('1.00', $currency);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCurrencies(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['US'];
        yield 'too long' => ['USDD'];
        yield 'lowercase' => ['usd'];
        yield 'digits' => ['US1'];
    }

    public function testAddingDifferentCurrenciesThrows(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Amount::of('1.00', 'USD')->plus(Amount::of('1.00', 'EUR'));
    }

    public function testArithmetic(): void
    {
        $a = Amount::of('10.505', 'USD');
        $b = Amount::of('2.5', 'USD');

        self::assertSame('13.005', (string) $a->plus($b)->value());
        self::assertSame('8.005', (string) $a->minus($b)->value());
        self::assertSame('21.01000', (string) $a->multipliedBy(Decimal::of('2.00'))->value());
        self::assertSame('-10.505', (string) $a->negated()->value());
        self::assertSame('10.505', (string) $a->negated()->abs()->value());
    }

    public function testZeroAndSignHelpers(): void
    {
        self::assertTrue(Amount::zero('PLN')->isZero());
        self::assertTrue(Amount::of('-0.01', 'PLN')->isNegative());
        self::assertSame(1, Amount::of('2', 'PLN')->compareTo(Amount::of('1', 'PLN')));
    }

    public function testToScaleRoundsToGrosze(): void
    {
        self::assertSame('0.20', (string) Amount::of('0.2025', 'PLN')->toScale(2)->value());
        self::assertSame('0.21', (string) Amount::of('0.2050', 'PLN')->toScale(2)->value());
    }

    public function testSumRequiresConsistentCurrency(): void
    {
        $sum = Amount::sum('PLN', Amount::of('1.11', 'PLN'), Amount::of('2.22', 'PLN'));

        self::assertSame('3.33', (string) $sum->value());
        self::assertSame('0', (string) Amount::sum('PLN')->value());
    }
}

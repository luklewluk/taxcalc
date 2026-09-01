<?php

declare(strict_types=1);

namespace App\Tests\Unit\Money;

use App\Exception\InvalidNumberException;
use App\Money\Decimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Decimal::class)]
final class DecimalTest extends TestCase
{
    public function testOfKeepsExactDecimalRepresentation(): void
    {
        self::assertSame('71.8275', (string) Decimal::of('71.8275'));
        self::assertSame('-0.2025', (string) Decimal::of('-0.2025'));
        self::assertSame('0', (string) Decimal::zero());
    }

    #[DataProvider('invalidNumbers')]
    public function testOfRejectsGarbage(string $input): void
    {
        $this->expectException(InvalidNumberException::class);

        Decimal::of($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNumbers(): iterable
    {
        yield 'empty' => [''];
        yield 'letters' => ['abc'];
        yield 'thousand separator' => ['1,234.56'];
        yield 'trailing garbage' => ['12.5zł'];
        yield 'infinity' => ['INF'];
        yield 'hex' => ['0x1A'];
    }

    public function testArithmeticIsExactAndNotFloatBased(): void
    {
        // 0.1 + 0.2 === 0.3 exactly, which binary floats cannot do.
        self::assertSame('0.3', (string) Decimal::of('0.1')->plus(Decimal::of('0.2')));
        self::assertSame('0.00', (string) Decimal::of('1.10')->minus(Decimal::of('1.10')));
        self::assertSame('0.2025', (string) Decimal::of('1.35')->multipliedBy(Decimal::of('0.15')));
    }

    public function testDividedByUsesExplicitScaleAndHalfUpRounding(): void
    {
        self::assertSame('0.3333', (string) Decimal::of('1')->dividedBy(Decimal::of('3'), 4));
        self::assertSame('0.67', (string) Decimal::of('2')->dividedBy(Decimal::of('3'), 2));
        self::assertSame('2.5', (string) Decimal::of('5')->dividedBy(Decimal::of('2'), 1));
    }

    public function testDividedByZeroThrows(): void
    {
        $this->expectException(InvalidNumberException::class);

        Decimal::of('1')->dividedBy(Decimal::zero(), 2);
    }

    public function testToScaleRoundsHalfUpAwayFromZero(): void
    {
        self::assertSame('1.24', (string) Decimal::of('1.235')->toScale(2));
        self::assertSame('-1.24', (string) Decimal::of('-1.235')->toScale(2));
        self::assertSame('1.00', (string) Decimal::of('0.999')->toScale(2));
        self::assertSame('2', (string) Decimal::of('1.5')->toScale(0));
    }

    public function testComparisonsAndSigns(): void
    {
        self::assertSame(0, Decimal::of('1.50')->compareTo(Decimal::of('1.5')));
        self::assertSame(-1, Decimal::of('1.4')->compareTo(Decimal::of('1.5')));
        self::assertSame(1, Decimal::of('1.6')->compareTo(Decimal::of('1.5')));

        self::assertTrue(Decimal::of('-0.01')->isNegative());
        self::assertFalse(Decimal::of('0.00')->isNegative());
        self::assertTrue(Decimal::of('0.00')->isZero());
        self::assertTrue(Decimal::of('0.01')->isPositive());
    }

    public function testAbsAndNegated(): void
    {
        self::assertSame('0.2025', (string) Decimal::of('-0.2025')->abs());
        self::assertSame('0.2025', (string) Decimal::of('0.2025')->abs());
        self::assertSame('-3', (string) Decimal::of('3')->negated());
    }

    public function testMinAndMax(): void
    {
        self::assertSame('1.5', (string) Decimal::of('1.5')->min(Decimal::of('2.5')));
        self::assertSame('2.5', (string) Decimal::of('1.5')->max(Decimal::of('2.5')));
    }

    public function testPercentageOf(): void
    {
        // 19% of 1000 PLN
        self::assertSame('190.00', (string) Decimal::of('1000')->percentage(Decimal::of('19'))->toScale(2));
    }

    /**
     * A share of something is a ratio. Dividing first and multiplying afterwards
     * loses money in proportion to the amount, because a share such as 1/3 has no
     * finite decimal form to divide into.
     */
    public function testMultipliedByRatioKeepsTheShareExactUntilTheFinalRounding(): void
    {
        $billion = Decimal::of('1000000000.00');

        self::assertSame(
            '333333333.33',
            (string) $billion->multipliedByRatio(Decimal::of('1'), Decimal::of('3'), 2),
        );

        // The same computation via an intermediate six-digit share is 333.33 short.
        self::assertSame(
            '333333000.00',
            (string) $billion->multipliedBy($billion->dividedBy(Decimal::of('3'), 6)->dividedBy($billion, 6))->toScale(2),
        );
    }

    public function testMultipliedByRatioHandlesFractionalPartsAndWholes(): void
    {
        self::assertSame(
            '33.33',
            (string) Decimal::of('100.00')->multipliedByRatio(Decimal::of('0.5432'), Decimal::of('1.6296'), 2),
        );

        // A whole share returns the value itself, at the requested scale.
        self::assertSame(
            '100.00',
            (string) Decimal::of('100.00')->multipliedByRatio(Decimal::of('7'), Decimal::of('7'), 2),
        );
    }

    public function testMultipliedByRatioRejectsAZeroWhole(): void
    {
        $this->expectException(InvalidNumberException::class);

        Decimal::of('100.00')->multipliedByRatio(Decimal::of('1'), Decimal::zero(), 2);
    }

    public function testOfAcceptsIntegersAndPlainDecimalStrings(): void
    {
        self::assertSame('7', (string) Decimal::of(7));
        self::assertSame('-7', (string) Decimal::of(-7));
        self::assertSame('1000', (string) Decimal::of('1e3'));
    }

    public function testNegativeScaleIsRejectedInsteadOfSilentlyRoundingToTens(): void
    {
        $this->expectException(InvalidNumberException::class);

        Decimal::of('1234.56')->toScale(-2);
    }

    public function testNegativeScaleIsRejectedInDivision(): void
    {
        $this->expectException(InvalidNumberException::class);

        Decimal::of('1')->dividedBy(Decimal::of('3'), -1);
    }
}

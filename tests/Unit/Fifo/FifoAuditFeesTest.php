<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fifo;

use App\Fifo\FifoMatcher;
use App\Fifo\Trade;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FifoAuditFeesTest extends TestCase
{
    public function testAuditFeesAreProratedAndFinalSliceGetsRemainderWithoutChangingTotals(): void
    {
        $result = (new FifoMatcher())->match([
            new Trade('AAA', new DateTimeImmutable('2024-01-01'), Decimal::of('3'), Amount::of('100.00', 'USD'),
                broker: 'X', commission: Amount::of('1.00', 'USD'), autoFx: Amount::of('0.10', 'USD')),
            new Trade('AAA', new DateTimeImmutable('2025-01-01'), Decimal::of('-1'), Amount::of('60.00', 'USD'),
                broker: 'X', commission: Amount::of('0.10', 'USD'), autoFx: Amount::of('0', 'USD')),
            new Trade('AAA', new DateTimeImmutable('2025-02-01'), Decimal::of('-1'), Amount::of('60.00', 'USD'),
                broker: 'X', commission: Amount::of('0.10', 'USD')),
            new Trade('AAA', new DateTimeImmutable('2025-03-01'), Decimal::of('-1'), Amount::of('60.00', 'USD'),
                broker: 'X', commission: Amount::of('0.10', 'USD')),
        ]);

        self::assertSame(['33.33', '33.33', '33.34'], array_map(static fn ($m): string => (string) $m->buyCost->value(), $result->matches));
        self::assertSame(['0.33', '0.33', '0.34'], array_map(static fn ($m): string => (string) $m->buyCommission?->value(), $result->matches));
        self::assertSame('0', (string) $result->matches[0]->sellAutoFx?->value());
        self::assertNull($result->matches[1]->sellAutoFx);
    }

    /**
     * Non-final slices are prorated from the whole with half-up rounding while
     * the final slice takes what is left, so their sum can overshoot: 0.02 over
     * four one-share sells rounds to 0.01 three times and leaves -0.01.
     *
     * Harmless while the fee was a display column; once it is grossed into
     * przychód it would put the declared revenue *below* the settled cash and
     * hand PIT-38 a negative cost.
     */
    public function testNoFeeSliceIsEverNegative(): void
    {
        $trades = [
            new Trade('AAA', new DateTimeImmutable('2024-01-01'), Decimal::of('4'), Amount::of('100.00', 'USD'),
                broker: 'X', commission: Amount::of('0.02', 'USD')),
        ];
        foreach (['2025-01-01', '2025-02-01', '2025-03-01', '2025-04-01'] as $date) {
            $trades[] = new Trade('AAA', new DateTimeImmutable($date), Decimal::of('-1'), Amount::of('60.00', 'USD'),
                broker: 'X', commission: Amount::of('0.02', 'USD'));
        }

        $result = (new FifoMatcher())->match($trades);

        $slices = array_map(static fn ($m): string => (string) $m->buyCommission?->value(), $result->matches);

        self::assertCount(4, $slices);
        foreach ($slices as $slice) {
            self::assertFalse(Decimal::of($slice)->isNegative(), sprintf('Kawałek prowizji %s jest ujemny.', $slice));
        }
        self::assertSame('0.02', (string) Amount::sum('USD', ...array_map(
            static fn (string $slice): Amount => Amount::of($slice, 'USD'),
            $slices,
        ))->value());
    }

    public function testDifferentBrokerPoolsNeverShareLots(): void
    {
        $result = (new FifoMatcher())->match([
            new Trade('AAA', new DateTimeImmutable('2024-01-01'), Decimal::of('1'), Amount::of('10', 'USD'), broker: 'A'),
            new Trade('AAA', new DateTimeImmutable('2025-01-01'), Decimal::of('-1'), Amount::of('20', 'USD'), broker: 'B'),
        ]);

        self::assertCount(0, $result->matches);
        self::assertCount(1, $result->unmatchedSells);
    }
}

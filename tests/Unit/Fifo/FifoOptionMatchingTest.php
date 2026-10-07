<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fifo;

use App\Fifo\FifoMatcher;
use App\Fifo\FifoViolationKind;
use App\Fifo\InstrumentKind;
use App\Fifo\PositionDirection;
use App\Fifo\PositionEffect;
use App\Fifo\Trade;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Option queues: either side may open, the declared effect decides what a
 * trade does, and a close that finds nothing to close is as fatal as a sale
 * without a purchase.
 */
#[CoversClass(FifoMatcher::class)]
final class FifoOptionMatchingTest extends TestCase
{
    private const string PUT = 'AAA 16JAN26 50 P';

    public function testAWrittenPutThatExpiresIsOneShortMatch(): void
    {
        $open = self::option('2025-12-15', '-1', '99', '1', PositionEffect::Open);
        $expiry = self::option('2026-01-16', '1', '0', '0', PositionEffect::Close);

        $result = (new FifoMatcher())->match([$expiry, $open]);

        self::assertSame([], $result->violations);
        self::assertCount(1, $result->matches);

        $match = $result->matches[0];
        self::assertSame(InstrumentKind::Option, $match->kind);
        self::assertSame(PositionDirection::Short, $match->direction);
        self::assertSame('2026-01-16', $match->buyDate->format('Y-m-d'));
        self::assertSame('2025-12-15', $match->sellDate->format('Y-m-d'));
        self::assertSame('2026-01-16', $match->closeDate()->format('Y-m-d'));
        self::assertSame('0', (string) $match->buyCost->value());
        self::assertSame('99', (string) $match->sellProceeds->value());
        self::assertSame('1', (string) $match->sellCommission?->value());
        self::assertSame('0', (string) $match->buyCommission?->value());
        self::assertSame($expiry->id(), $match->buyTradeId);
        self::assertSame($open->id(), $match->sellTradeId);
    }

    public function testAPartiallyClosedShortGivesTheLastSliceTheExactRemainder(): void
    {
        $result = (new FifoMatcher())->match([
            self::option('2026-03-02', '-2', '297.90', '2.10', PositionEffect::Open),
            self::option('2026-03-20', '1', '51.05', '1.05', PositionEffect::Close),
            self::option('2026-04-17', '1', '0', '0', PositionEffect::Close),
        ]);

        self::assertSame([], $result->violations);
        self::assertSame(
            [['148.95', '1.05', '51.05'], ['148.95', '1.05', '0']],
            array_map(static fn ($m): array => [
                (string) $m->sellProceeds->value(),
                (string) $m->sellCommission?->value(),
                (string) $m->buyCost->value(),
            ], $result->matches),
        );
    }

    public function testOneOrderCanCloseALongAndOpenAShortWithTheRest(): void
    {
        $result = (new FifoMatcher())->match([
            self::option('2026-05-04', '1', '120.65', '0.65', PositionEffect::Open),
            self::option('2026-06-01', '-2', '398.70', '1.30', PositionEffect::CloseThenOpen),
            self::option('2026-07-01', '1', '90.65', '0.65', PositionEffect::Close),
        ]);

        self::assertSame([], $result->violations);
        self::assertCount(2, $result->matches);

        [$long, $short] = $result->matches;
        self::assertSame(PositionDirection::Long, $long->direction);
        self::assertSame('120.65', (string) $long->buyCost->value());
        self::assertSame('199.35', (string) $long->sellProceeds->value());
        self::assertSame('0.65', (string) $long->sellCommission?->value());

        self::assertSame(PositionDirection::Short, $short->direction);
        self::assertSame('199.35', (string) $short->sellProceeds->value());
        self::assertSame('0.65', (string) $short->sellCommission?->value());
        self::assertSame('90.65', (string) $short->buyCost->value());
        self::assertSame([1, 2], [$long->sequence, $short->sequence]);
    }

    public function testAClosingBuyWithNothingToCloseIsAViolation(): void
    {
        $result = (new FifoMatcher())->match([
            self::option('2026-01-16', '1', '0', '0', PositionEffect::Close),
        ]);

        self::assertSame([], $result->matches);
        self::assertCount(1, $result->violations);
        self::assertSame(FifoViolationKind::UnmatchedClose, $result->violations[0]->kind);
        self::assertSame('1', (string) $result->violations[0]->quantity);
    }

    public function testClosingMoreThanIsOpenLeavesTheExcessAsAViolation(): void
    {
        $result = (new FifoMatcher())->match([
            self::option('2026-05-04', '1', '120.65', '0.65', PositionEffect::Open),
            self::option('2026-06-01', '-2', '398.70', '1.30', PositionEffect::Close),
        ]);

        self::assertCount(1, $result->matches);
        self::assertSame(FifoViolationKind::UnmatchedClose, $result->violations[0]->kind);
        self::assertSame('1', (string) $result->violations[0]->quantity);
    }

    public function testACloseThenOpenWithNothingToCloseIsAViolationNotAnOpen(): void
    {
        // The long call it closes was opened in a statement that was not
        // uploaded: opening the whole quantity short would lose that sale.
        $result = (new FifoMatcher())->match([
            self::option('2026-06-01', '-3', '598.05', '1.95', PositionEffect::CloseThenOpen),
            self::option('2026-07-01', '2', '180.00', '1.30', PositionEffect::Close),
        ]);

        self::assertSame([], $result->matches);
        self::assertSame(FifoViolationKind::UnmatchedClose, $result->violations[0]->kind);
        self::assertSame('3', (string) $result->violations[0]->quantity);
    }

    public function testOpeningAgainstAnOppositePositionIsAViolation(): void
    {
        $result = (new FifoMatcher())->match([
            self::option('2026-05-04', '1', '120.65', '0.65', PositionEffect::Open),
            self::option('2026-06-01', '-1', '199.35', '0.65', PositionEffect::Open),
        ]);

        self::assertSame(FifoViolationKind::OpenAgainstOpposite, $result->violations[0]->kind);
    }

    public function testAnOptionWithoutADeclaredEffectIsAViolation(): void
    {
        $result = (new FifoMatcher())->match([
            self::option('2026-05-04', '1', '120.65', '0.65', null),
        ]);

        self::assertSame(FifoViolationKind::MissingEffect, $result->violations[0]->kind);
    }

    public function testAQueueMixingStocksAndOptionsIsAViolation(): void
    {
        $stock = new Trade(self::PUT, new DateTimeImmutable('2026-05-04'), Decimal::of('1'), Amount::of('10', 'USD'), broker: 'IBKR', fifoPool: self::PUT.'@USD');

        $result = (new FifoMatcher())->match([
            $stock,
            self::option('2026-05-05', '-1', '9', '0', PositionEffect::Open),
        ]);

        self::assertSame([], $result->matches);
        self::assertSame(FifoViolationKind::MixedInstrumentKinds, $result->violations[0]->kind);
    }

    public function testAStockSaleWithoutAPurchaseIsStillAnUnmatchedSell(): void
    {
        $result = (new FifoMatcher())->match([
            new Trade('AAA', new DateTimeImmutable('2026-05-04'), Decimal::of('-1'), Amount::of('10', 'USD'), broker: 'IBKR'),
        ]);

        self::assertSame([], $result->violations);
        self::assertCount(1, $result->unmatchedSells);
    }

    public function testEveryViolationExplainsItselfInPolish(): void
    {
        $result = (new FifoMatcher())->match([
            self::option('2026-01-16', '1', '0', '0', PositionEffect::Close),
        ]);

        self::assertStringContainsString(self::PUT, $result->violations[0]->describe());
        self::assertStringContainsString('2026-01-16', $result->violations[0]->describe());
    }

    private static function option(string $date, string $quantity, string $total, string $commission, ?PositionEffect $effect): Trade
    {
        return new Trade(
            self::PUT,
            new DateTimeImmutable($date.' 10:00:00'),
            Decimal::of($quantity),
            Amount::of($total, 'USD'),
            externalId: 'auto:'.$date.$quantity,
            broker: 'IBKR',
            commission: Amount::of($commission, 'USD'),
            fifoPool: self::PUT.'@USD',
            kind: InstrumentKind::Option,
            effect: $effect,
        );
    }
}

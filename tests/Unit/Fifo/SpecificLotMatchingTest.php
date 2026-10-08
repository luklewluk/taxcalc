<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fifo;

use App\Fifo\FifoMatch;
use App\Fifo\FifoMatcher;
use App\Fifo\FifoViolationKind;
use App\Fifo\InstrumentKind;
use App\Fifo\LotAllocation;
use App\Fifo\LotAssignments;
use App\Fifo\LotMethod;
use App\Fifo\PositionEffect;
use App\Fifo\Trade;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Specific identification next to the default FIFO: a sale the user tied to
 * named lots consumes exactly those (KIS 0112-KDIL2-1.4011.929.2025.1.TR, FIFO
 * of art. 30b ust. 7 applies only when the units sold cannot be identified).
 * Every other sale stays FIFO, and a broken assignment is never quietly
 * replaced by FIFO.
 */
#[CoversClass(FifoMatcher::class)]
#[CoversClass(LotAssignments::class)]
final class SpecificLotMatchingTest extends TestCase
{
    public function testWithoutAssignmentsTheResultIsUnchanged(): void
    {
        $trades = $this->twoLotsTwoSales();
        $matcher = new FifoMatcher();

        self::assertEquals($matcher->match($trades), $matcher->match($trades, new LotAssignments()));
    }

    public function testAManualSaleTakesTheChosenLotAndTheNextFifoSaleTakesTheOlderOne(): void
    {
        [$older, $newer, $first, $second] = $this->twoLotsTwoSales();

        $result = (new FifoMatcher())->match(
            [$older, $newer, $first, $second],
            self::assign($first, [$newer, '10']),
        );

        self::assertSame([], $result->violations);
        self::assertCount(2, $result->matches);
        [$manual, $fifo] = $result->matches;

        self::assertSame($first->id(), $manual->sellTradeId);
        self::assertSame($newer->id(), $manual->buyTradeId);
        self::assertSame('1500.00', (string) $manual->buyCost->value());
        self::assertSame(LotMethod::Specific, $manual->lotMethod);

        self::assertSame($second->id(), $fifo->sellTradeId);
        self::assertSame($older->id(), $fifo->buyTradeId);
        self::assertSame('1000.00', (string) $fifo->buyCost->value());
        self::assertSame(LotMethod::Fifo, $fifo->lotMethod);
    }

    public function testASaleSplitAcrossLotsIsProratedExactlyAndListedInQueueOrder(): void
    {
        $older = self::trade('2024-01-10', '10', '1000.00');
        $newer = self::trade('2024-02-10', '10', '1500.00');
        $sale = self::trade('2024-03-10', '-12', '2400.00');

        // The newer lot is named first; the matches still follow the queue.
        $result = (new FifoMatcher())->match(
            [$older, $newer, $sale],
            self::assign($sale, [$newer, '8'], [$older, '4']),
        );

        self::assertSame([], $result->violations);
        self::assertSame([$older->id(), $newer->id()], array_map(static fn (FifoMatch $m): string => $m->buyTradeId, $result->matches));
        self::assertSame(['400.00', '1200.00'], array_map(static fn (FifoMatch $m): string => (string) $m->buyCost->value(), $result->matches));
        self::assertSame(['800.00', '1600.00'], array_map(static fn (FifoMatch $m): string => (string) $m->sellProceeds->value(), $result->matches));
    }

    public function testAPartlyUsedLotLeavesItsExactRemainderToALaterFifoSale(): void
    {
        $lot = self::trade('2024-01-10', '3', '100.00');
        $manual = self::trade('2024-02-10', '-1', '40.00');
        $fifo = self::trade('2024-03-10', '-2', '80.00');

        $result = (new FifoMatcher())->match([$lot, $manual, $fifo], self::assign($manual, [$lot, '1']));

        self::assertSame(['33.33', '66.67'], array_map(static fn (FifoMatch $m): string => (string) $m->buyCost->value(), $result->matches));
    }

    public function testQuantitiesThatDoNotAddUpToTheSaleBlockItWithoutFallingBackToFifo(): void
    {
        [$older, $newer, $first, $second] = $this->twoLotsTwoSales();

        $result = (new FifoMatcher())->match([$older, $newer, $first, $second], self::assign($first, [$newer, '8']));

        self::assertCount(1, $result->violations);
        self::assertSame(FifoViolationKind::LotQuantityMismatch, $result->violations[0]->kind);
        self::assertSame($first->id(), $result->violations[0]->tradeId);
        self::assertStringContainsString('10', $result->violations[0]->describe());
        self::assertStringContainsString('8', $result->violations[0]->describe());
        self::assertSame([], array_values(array_filter($result->matches, static fn (FifoMatch $m): bool => $m->sellTradeId === $first->id())));
        self::assertSame([], $result->unmatchedSells, 'An invalid assignment is reported once, not again as an uncovered sale.');
    }

    public function testALotBoughtAfterTheSaleCannotBeNamed(): void
    {
        $older = self::trade('2024-01-10', '10', '1000.00');
        $sale = self::trade('2024-02-10', '-10', '2000.00');
        $later = self::trade('2024-03-10', '10', '1500.00');

        $result = (new FifoMatcher())->match([$older, $sale, $later], self::assign($sale, [$later, '10']));

        self::assertSame(FifoViolationKind::LotNotYetOpen, $result->violations[0]->kind);
        self::assertStringContainsString('2024-03-10', $result->violations[0]->describe());
    }

    public function testALotAnEarlierSaleAlreadyUsedUpIsReportedWithWhatIsLeft(): void
    {
        [$older, $newer, $first, $second] = $this->twoLotsTwoSales();

        // The first sale is FIFO and empties the older lot; the second names it.
        $result = (new FifoMatcher())->match([$older, $newer, $first, $second], self::assign($second, [$older, '5'], [$newer, '5']));

        $violation = $result->violations[0];
        self::assertSame(FifoViolationKind::LotInsufficient, $violation->kind);
        self::assertSame('2024-01-10', $violation->lotDate?->format('Y-m-d'));
        self::assertSame('5', (string) $violation->requested);
        self::assertSame('0', (string) $violation->available);
        self::assertStringContainsString('2024-01-10', $violation->describe());
    }

    public function testALotOfAnotherInstrumentIsNotEligible(): void
    {
        $lot = self::trade('2024-01-10', '10', '1000.00');
        $other = self::trade('2024-01-11', '10', '900.00', 'BBB');
        $sale = self::trade('2024-02-10', '-10', '2000.00');

        $result = (new FifoMatcher())->match([$lot, $other, $sale], self::assign($sale, [$other, '10']));

        self::assertSame(FifoViolationKind::LotNotEligible, $result->violations[0]->kind);
    }

    public function testAnUnknownLotIsMissing(): void
    {
        $lot = self::trade('2024-01-10', '10', '1000.00');
        $sale = self::trade('2024-02-10', '-10', '2000.00');

        $result = (new FifoMatcher())->match([$lot, $sale], new LotAssignments([
            $sale->id() => [new LotAllocation('no-such-lot', Decimal::of('10'))],
        ]));

        self::assertSame(FifoViolationKind::LotMissing, $result->violations[0]->kind);
    }

    public function testAnAssignmentOnABuyOrAnOptionIsNotASale(): void
    {
        $lot = self::trade('2024-01-10', '10', '1000.00');
        $option = self::option('2024-02-10', '-1', '150.00', PositionEffect::Open);
        $close = self::option('2024-03-10', '1', '40.00', PositionEffect::Close);

        $result = (new FifoMatcher())->match([$lot, $option, $close], new LotAssignments([
            $lot->id() => [new LotAllocation($lot->id(), Decimal::of('1'))],
            $option->id() => [new LotAllocation($lot->id(), Decimal::of('1'))],
        ]));

        $kinds = array_map(static fn ($v) => $v->kind, $result->violations);
        self::assertSame([FifoViolationKind::AssignmentNotASale, FifoViolationKind::AssignmentNotASale], $kinds);
        // The option series still settles exactly as it does without assignments.
        $options = array_values(array_filter($result->matches, static fn (FifoMatch $m): bool => InstrumentKind::Option === $m->kind));
        self::assertEquals((new FifoMatcher())->match([$option, $close])->matches, $options);
    }

    public function testNamingTheLotFifoWouldPickKeepsTheLineage(): void
    {
        $lot = self::trade('2024-01-10', '10', '1000.00', 'AAA', 'b-1');
        $sale = self::trade('2024-02-10', '-10', '2000.00', 'AAA', 's-1');

        $fifo = (new FifoMatcher())->match([$lot, $sale])->matches[0];
        $named = (new FifoMatcher())->match([$lot, $sale], self::assign($sale, [$lot, '10']))->matches[0];

        self::assertNotNull($fifo->lineageKey());
        self::assertSame($fifo->lineageKey(), $named->lineageKey());
        self::assertSame(LotMethod::Specific, $named->lotMethod);
    }

    public function testAnotherInstrumentAndEarlierSalesKeepTheirLineage(): void
    {
        $a1 = self::trade('2024-01-10', '10', '1000.00', 'AAA', 'a1');
        $a2 = self::trade('2024-01-20', '10', '1100.00', 'AAA', 'a2');
        $aEarly = self::trade('2024-02-01', '-5', '600.00', 'AAA', 'a-early');
        $aManual = self::trade('2024-03-01', '-5', '700.00', 'AAA', 'a-manual');
        $b1 = self::trade('2024-01-15', '10', '500.00', 'BBB', 'b1');
        $bSale = self::trade('2024-03-01', '-10', '700.00', 'BBB', 'b-sale');
        $trades = [$a1, $a2, $aEarly, $aManual, $b1, $bSale];

        $plain = (new FifoMatcher())->match($trades);
        $manual = (new FifoMatcher())->match($trades, self::assign($aManual, [$a2, '5']));

        $key = static fn (FifoMatch $m): string => (string) $m->lineageKey();
        $bySell = static function (array $matches, string $sellId) use ($key): array {
            return array_map($key, array_values(array_filter($matches, static fn (FifoMatch $m): bool => $m->sellTradeId === $sellId)));
        };

        self::assertSame($bySell($plain->matches, $bSale->id()), $bySell($manual->matches, $bSale->id()));
        self::assertSame($bySell($plain->matches, $aEarly->id()), $bySell($manual->matches, $aEarly->id()));
    }

    public function testTheLotsOpenJustBeforeASaleAreWhatTheEditorOffers(): void
    {
        [$older, $newer, $first, $second] = $this->twoLotsTwoSales();
        $matcher = new FifoMatcher();

        $before = $matcher->openLotsBefore([$older, $newer, $first, $second], new LotAssignments(), $second->id());

        self::assertNotNull($before);
        self::assertSame([$newer->id()], array_map(static fn ($lot): string => $lot->trade->id(), $before));
        self::assertSame('10', (string) $before[0]->quantity);
        self::assertNull($matcher->openLotsBefore([$older, $first], new LotAssignments(), 'unknown'));
    }

    /**
     * @return array{Trade, Trade, Trade, Trade}
     */
    private function twoLotsTwoSales(): array
    {
        return [
            self::trade('2024-01-10', '10', '1000.00'),
            self::trade('2024-02-10', '10', '1500.00'),
            self::trade('2024-03-10', '-10', '2000.00'),
            self::trade('2024-04-10', '-10', '2200.00'),
        ];
    }

    /**
     * @param array{Trade, string} ...$lots
     */
    private static function assign(Trade $sale, array ...$lots): LotAssignments
    {
        return new LotAssignments([
            $sale->id() => array_map(static fn (array $lot): LotAllocation => new LotAllocation($lot[0]->id(), Decimal::of($lot[1])), $lots),
        ]);
    }

    private static function trade(string $date, string $quantity, string $total, string $symbol = 'AAA', ?string $externalId = null): Trade
    {
        return new Trade($symbol, new DateTimeImmutable($date), Decimal::of($quantity), Amount::of($total, 'USD'), $externalId);
    }

    private static function option(string $date, string $quantity, string $total, PositionEffect $effect): Trade
    {
        return new Trade(
            'AAA 21MAR25 90 P',
            new DateTimeImmutable($date),
            Decimal::of($quantity),
            Amount::of($total, 'USD'),
            kind: InstrumentKind::Option,
            effect: $effect,
        );
    }
}

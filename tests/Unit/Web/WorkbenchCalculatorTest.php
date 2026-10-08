<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Fifo\FifoMatcher;
use App\Fifo\InstrumentDetails;
use App\Fifo\InstrumentKind;
use App\Fifo\LotAllocation;
use App\Fifo\LotAssignments;
use App\Fifo\LotMethod;
use App\Fifo\PositionDirection;
use App\Fifo\PositionEffect;
use App\Fifo\Trade;
use App\Money\Amount;
use App\Money\Decimal;
use App\Settlement\SettlementCycle;
use App\Web\WorkbenchCalculator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Beside the positions that reach the return, a settlement exposes which
 * position each FIFO match became and what is still open, so the workbench can
 * explain every trade without running FIFO a second time.
 */
#[CoversClass(WorkbenchCalculator::class)]
final class WorkbenchCalculatorTest extends TestCase
{
    /**
     * The link is keyed by the *match* index, not by the position's place in
     * the list: a match that cannot become a position (bought in USD, sold in
     * EUR) comes first here, so the two indexes differ.
     */
    public function testEveryMatchIsLinkedToThePositionItBecameEvenAfterAMatchThatBecameNone(): void
    {
        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle([
            self::trade('BBB', '2024-01-02', '1', '100.00', 'USD'),
            self::trade('BBB', '2024-02-02', '-1', '120.00', 'EUR'),
            self::trade('AAA', '2024-01-02', '10', '1000.00', 'USD'),
            self::trade('AAA', '2024-03-02', '-4', '600.00', 'USD'),
        ]);

        self::assertCount(2, $settlement->matches);
        self::assertSame('BBB', $settlement->matches[0]->symbol);
        self::assertSame('AAA', $settlement->matches[1]->symbol);
        self::assertCount(1, $settlement->positions);
        self::assertSame([1], array_keys($settlement->matchPositions), 'A match that could not become a position has no entry.');
        self::assertSame($settlement->positions[0], $settlement->matchPositions[1]);
    }

    public function testWhatFifoLeftOpenOrUnmatchedIsPassedThrough(): void
    {
        $buy = self::trade('AAA', '2024-01-02', '10', '1000.00', 'USD');
        $orphan = self::trade('BBB', '2024-03-02', '-1', '120.00', 'USD');

        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle([
            $buy,
            self::trade('AAA', '2024-03-02', '-4', '600.00', 'USD'),
            $orphan,
        ]);

        self::assertCount(1, $settlement->openPositions);
        self::assertSame($buy, $settlement->openPositions[0]->trade);
        self::assertSame('6', (string) $settlement->openPositions[0]->quantity);
        self::assertSame(PositionDirection::Long, $settlement->openPositions[0]->direction);
        self::assertCount(1, $settlement->unmatchedSells);
        self::assertSame($orphan->id(), $settlement->unmatchedSells[0]->tradeId);
        self::assertSame([], $settlement->violations);
    }

    public function testNamedLotsReachThePositionsTheyBecome(): void
    {
        $older = self::trade('AAA', '2024-01-10', '10', '1000.00', 'USD');
        $newer = self::trade('AAA', '2024-02-10', '10', '1500.00', 'USD');
        $sale = self::trade('AAA', '2024-03-10', '-10', '2000.00', 'USD');

        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle(
            [$older, $newer, $sale],
            new LotAssignments([$sale->id() => [new LotAllocation($newer->id(), Decimal::of('10'))]]),
        );

        self::assertSame([], $settlement->errors);
        self::assertSame('1500.00', (string) $settlement->positions[0]->buyAmount->value());
        self::assertSame(LotMethod::Specific, $settlement->positions[0]->lotMethod);
    }

    /**
     * A named lot that cannot be honoured blocks the result and points at the
     * FIFO tab, where the lots are chosen - never a quiet return to FIFO.
     */
    public function testAnAssignmentThatCannotBeHonouredBlocksAndPointsAtTheFifoTab(): void
    {
        $lot = self::trade('AAA', '2024-01-10', '10', '1000.00', 'USD');
        $sale = self::trade('AAA', '2024-03-10', '-10', '2000.00', 'USD');

        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle(
            [$lot, $sale],
            new LotAssignments([$sale->id() => [new LotAllocation($lot->id(), Decimal::of('4'))]]),
        );

        self::assertNotEmpty($settlement->errors);
        self::assertSame([], $settlement->positions);
        $diagnostic = $settlement->diagnostics[0];
        self::assertSame('fifo.lot_quantity_mismatch', $diagnostic->code);
        self::assertSame(\App\Web\DiagnosticLevel::Blocking, $diagnostic->level);
        self::assertSame('fifo', $diagnostic->targetTab);
        self::assertSame($sale->id(), $diagnostic->rowId);
    }

    public function testByDefaultNoLegIsMovedOffItsTradeDate(): void
    {
        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle(self::alfa());

        self::assertNull($settlement->positions[0]->buySettlement);
        self::assertNull($settlement->positions[0]->sellSettlement);
        self::assertSame(2025, $settlement->positions[0]->taxYear());
    }

    /**
     * The listing venue's market decides the calendar: XNYS is New York, so the
     * sale of 31 December settles T+1 on 2 January - and moves to that year.
     */
    public function testTheMarketCycleSettlesEachLegOnItsOwnMarket(): void
    {
        $position = (new WorkbenchCalculator(new FifoMatcher()))
            ->settle(self::alfa(), cycle: SettlementCycle::Market)->positions[0];

        self::assertSame('2025-03-04', $position->buySettlement?->format('Y-m-d'));
        self::assertSame('2026-01-02', $position->sellSettlement?->format('Y-m-d'));
        self::assertSame(2026, $position->taxYear());
    }

    /** Without a venue the row's own country stands in for the market. */
    public function testWithoutAVenueTheCountryOfTheRowNamesTheMarket(): void
    {
        $trades = [
            self::trade('BETA', '2025-03-03', '1', '-100.00', 'EUR'),
            self::trade('BETA', '2025-12-23', '-1', '120.00', 'EUR'),
        ];
        $trades = array_map(static fn (Trade $trade): Trade => new Trade(
            $trade->symbol, $trade->date, $trade->quantity, $trade->grossAmount, $trade->externalId, $trade->source,
            new InstrumentDetails('BETA', 'DE'), broker: 'DEGIRO', fifoPool: 'BETA',
        ), $trades);

        $position = (new WorkbenchCalculator(new FifoMatcher()))->settle($trades, cycle: SettlementCycle::Market)->positions[0];

        // T+2 over Xetra's Christmas Eve and both Christmas days.
        self::assertSame('2025-12-30', $position->sellSettlement?->format('Y-m-d'));
    }

    /** An option that expired closes at nothing: there is no trade to settle. */
    public function testAnExpiryIsNeverMovedButTheWritingIs(): void
    {
        $option = static fn (string $date, string $quantity, string $total, PositionEffect $effect): Trade => new Trade(
            'AAA 16JAN26 50 P',
            new DateTimeImmutable($date.' 10:00:00'),
            Decimal::of($quantity),
            Amount::of($total, 'USD'),
            externalId: 'auto:'.$date,
            instrument: new InstrumentDetails('AAA 16JAN26 50 P', 'US', 'XCBO'),
            broker: 'IBKR',
            fifoPool: 'AAA 16JAN26 50 P@USD',
            kind: InstrumentKind::Option,
            effect: $effect,
        );

        $position = (new WorkbenchCalculator(new FifoMatcher()))->settle([
            $option('2025-12-15', '-1', '99', PositionEffect::Open),
            $option('2026-01-16', '1', '0', PositionEffect::Close),
        ], cycle: SettlementCycle::Market)->positions[0];

        self::assertSame('2025-12-16', $position->sellSettlement?->format('Y-m-d'));
        self::assertNull($position->buySettlement);
        self::assertSame('2026-01-16', $position->revenueDate()->format('Y-m-d'));
    }

    /** @return list<Trade> */
    private static function alfa(): array
    {
        return array_map(static fn (Trade $trade): Trade => new Trade(
            $trade->symbol, $trade->date, $trade->quantity, $trade->grossAmount, $trade->externalId, $trade->source,
            new InstrumentDetails('ALFA', 'US', 'XNYS'), broker: 'IBKR', fifoPool: 'ALFA',
        ), [
            self::trade('ALFA', '2025-03-03', '1', '-100.00', 'USD'),
            self::trade('ALFA', '2025-12-31', '-1', '120.00', 'USD'),
        ]);
    }

    private static function trade(string $symbol, string $date, string $quantity, string $total, string $currency): Trade
    {
        return new Trade(
            $symbol,
            new DateTimeImmutable($date.' 10:00:00'),
            Decimal::of($quantity),
            Amount::of($total, $currency),
            'auto:'.$symbol.$date.$quantity,
            'as.csv',
            new InstrumentDetails($symbol, 'US'),
            broker: 'IBKR',
            fifoPool: $symbol,
        );
    }
}

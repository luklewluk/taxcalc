<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Fifo\FifoMatcher;
use App\Fifo\InstrumentDetails;
use App\Fifo\PositionDirection;
use App\Fifo\Trade;
use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Money\Decimal;
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
    public function testEveryMatchIsLinkedToThePositionItBecameEvenAfterLegacyPositions(): void
    {
        $legacy = new ClosedPosition(
            'LEGACY',
            'US',
            'USD',
            new DateTimeImmutable('2023-01-01'),
            Amount::of('10.00', 'USD'),
            new DateTimeImmutable('2023-02-01'),
            Amount::of('12.00', 'USD'),
            Decimal::of('1'),
            'legacy.csv',
        );

        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle([
            self::trade('AAA', '2024-01-02', '10', '1000.00', 'USD'),
            self::trade('AAA', '2024-03-02', '-4', '600.00', 'USD'),
            self::trade('BBB', '2024-01-02', '1', '100.00', 'USD'),
            self::trade('BBB', '2024-03-02', '-1', '120.00', 'EUR'),
        ], [$legacy]);

        self::assertCount(2, $settlement->matches);
        self::assertSame($legacy, $settlement->positions[0]);
        self::assertSame([0], array_keys($settlement->matchPositions), 'A match that could not become a position has no entry.');
        self::assertSame($settlement->positions[1], $settlement->matchPositions[0]);
        self::assertSame('AAA', $settlement->matches[0]->symbol);
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

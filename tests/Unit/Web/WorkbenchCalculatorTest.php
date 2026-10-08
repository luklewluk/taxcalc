<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Fifo\FifoMatcher;
use App\Fifo\InstrumentDetails;
use App\Fifo\PositionDirection;
use App\Fifo\Trade;
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

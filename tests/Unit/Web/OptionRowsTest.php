<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Fifo\FifoMatcher;
use App\Fifo\InstrumentDetails;
use App\Fifo\InstrumentKind;
use App\Fifo\PositionDirection;
use App\Fifo\PositionEffect;
use App\Fifo\Trade;
use App\Money\Amount;
use App\Money\Decimal;
use App\Web\RowFormMapper;
use App\Web\WorkbenchCalculator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Option rows survive the stateless form round trip, and what FIFO refuses
 * reaches the attention panel as a blocking item on the row.
 */
#[CoversClass(RowFormMapper::class)]
#[CoversClass(WorkbenchCalculator::class)]
final class OptionRowsTest extends TestCase
{
    public function testAnExpiryAtZeroRoundTripsWithItsKindAndEffect(): void
    {
        $mapper = new RowFormMapper();
        $form = $mapper->tradeToForm(self::option('1', '0', '0', PositionEffect::Close));

        self::assertSame('OPT', $form['asset']);
        self::assertSame('close', $form['effect']);

        $result = $mapper->mapTrades([$form]);

        self::assertSame([], $result->errors);
        self::assertSame(InstrumentKind::Option, $result->trades[0]->kind);
        self::assertSame(PositionEffect::Close, $result->trades[0]->effect);
        self::assertTrue($result->trades[0]->grossAmount->isZero());
    }

    public function testAFormWithoutTheNewFieldsIsAStock(): void
    {
        $mapper = new RowFormMapper();
        $form = $mapper->tradeToForm(self::stock());
        unset($form['asset'], $form['effect']);

        $result = $mapper->mapTrades([$form]);

        self::assertSame([], $result->errors);
        self::assertSame(InstrumentKind::Stock, $result->trades[0]->kind);
        self::assertNull($result->trades[0]->effect);
    }

    public function testTheEffectIsIgnoredForAStock(): void
    {
        $mapper = new RowFormMapper();
        $result = $mapper->mapTrades([['effect' => 'close'] + $mapper->tradeToForm(self::stock())]);

        self::assertSame([], $result->errors);
        self::assertNull($result->trades[0]->effect);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidRows(): iterable
    {
        yield 'unknown asset' => [['asset' => 'FUT'], 'rodzaj instrumentu'];
        yield 'option without effect' => [['effect' => ''], 'otwiera, czy zamyka'];
        yield 'option opened at zero' => [['effect' => 'open', 'total' => '0'], 'zerowa'];
        yield 'option closed at zero with a fee' => [['total' => '0', 'commission' => '0.50'], 'opłat'];
        yield 'stock at zero' => [['asset' => 'STK', 'effect' => '', 'total' => '0'], 'Total'];
    }

    /**
     * @param array<string, string> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRows')]
    public function testAnInvalidOptionRowIsRefused(array $override, string $expected): void
    {
        $mapper = new RowFormMapper();
        $form = $override + $mapper->tradeToForm(self::option('1', '0', '0', PositionEffect::Close));

        $result = $mapper->mapTrades([$form]);

        self::assertSame([], $result->trades);
        self::assertStringContainsString($expected, implode(' ', $result->errors));
    }

    public function testAWrittenOptionSettlesAsAShortPosition(): void
    {
        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle([
            self::option('-1', '99', '1', PositionEffect::Open, '2025-12-15'),
            self::option('1', '0', '0', PositionEffect::Close, '2026-01-16'),
        ]);

        self::assertSame([], $settlement->errors);
        self::assertCount(1, $settlement->positions);

        $position = $settlement->positions[0];
        self::assertSame(InstrumentKind::Option, $position->kind);
        self::assertSame(PositionDirection::Short, $position->direction);
        self::assertSame('US', $position->countryCode);
        self::assertSame(2026, $position->taxYear());
    }

    public function testACloseWithoutAnOpenIsAReviewItemOnItsRow(): void
    {
        $close = self::option('1', '0', '0', PositionEffect::Close, '2026-01-16');
        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle([$close]);

        self::assertSame([], $settlement->errors, 'A missing earlier statement does not block the result.');
        self::assertSame('fifo.option_unmatched_close', $settlement->diagnostics[0]->code);
        self::assertSame(\App\Web\DiagnosticLevel::Review, $settlement->diagnostics[0]->level);
        self::assertSame($close->id(), $settlement->diagnostics[0]->rowId);
    }

    public function testAnOptionThatContradictsItselfStillBlocks(): void
    {
        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle([
            self::option('1', '120', '0.65', PositionEffect::Open, '2026-05-04'),
            self::option('-1', '199', '0.65', PositionEffect::Open, '2026-06-01'),
        ]);

        self::assertNotSame([], $settlement->errors);
        self::assertSame('fifo.option_open_against_position', $settlement->diagnostics[0]->code);
    }

    public function testAStockSaleWithoutAPurchaseIsAReviewItemAndTheRestSettles(): void
    {
        $orphan = new Trade('AAA', new DateTimeImmutable('2025-02-27 10:00:00'), Decimal::of('-31'), Amount::of('15000', 'USD'),
            'auto:orphan', 'as.csv', new InstrumentDetails('AAA', 'US'), broker: 'IBKR', fifoPool: 'AAA@USD');
        $buy = new Trade('BBB', new DateTimeImmutable('2025-01-02 10:00:00'), Decimal::of('1'), Amount::of('100', 'USD'),
            'auto:b', 'as.csv', new InstrumentDetails('BBB', 'US'), broker: 'IBKR', fifoPool: 'BBB@USD');
        $sell = new Trade('BBB', new DateTimeImmutable('2025-03-02 10:00:00'), Decimal::of('-1'), Amount::of('120', 'USD'),
            'auto:s', 'as.csv', new InstrumentDetails('BBB', 'US'), broker: 'IBKR', fifoPool: 'BBB@USD');

        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle([$orphan, $buy, $sell]);

        self::assertSame([], $settlement->errors);
        self::assertCount(1, $settlement->positions);
        self::assertSame('fifo.unmatched_sell', $settlement->diagnostics[0]->code);
        self::assertSame(\App\Web\DiagnosticLevel::Review, $settlement->diagnostics[0]->level);
        self::assertSame($orphan->id(), $settlement->diagnostics[0]->rowId);
        self::assertStringContainsString('wcześniejszy rok', $settlement->diagnostics[0]->message);
    }

    private static function option(string $quantity, string $total, string $commission, ?PositionEffect $effect, string $date = '2026-01-16'): Trade
    {
        return new Trade(
            'AAA 16JAN26 50 P',
            new DateTimeImmutable($date.' 10:00:00'),
            Decimal::of($quantity),
            Amount::of($total, 'USD'),
            'auto:'.$date.$quantity,
            'as.csv',
            new InstrumentDetails('AAA 16JAN26 50 P', 'US', 'XCBO'),
            broker: 'IBKR',
            commission: Amount::of($commission, 'USD'),
            fifoPool: 'AAA 16JAN26 50 P@USD',
            kind: InstrumentKind::Option,
            effect: $effect,
        );
    }

    private static function stock(): Trade
    {
        return new Trade(
            'AAA',
            new DateTimeImmutable('2024-01-01'),
            Decimal::of('1'),
            Amount::of('10.00', 'USD'),
            source: 'test',
            instrument: new InstrumentDetails('AAA', 'US'),
            broker: 'IBKR',
            stableId: 'trade-1',
            fifoPool: 'AAA@USD',
        );
    }
}

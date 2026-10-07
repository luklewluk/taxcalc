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

    public function testACloseWithoutAnOpenBlocksTheResultOnItsRow(): void
    {
        $close = self::option('1', '0', '0', PositionEffect::Close, '2026-01-16');
        $settlement = (new WorkbenchCalculator(new FifoMatcher()))->settle([$close]);

        self::assertNotSame([], $settlement->errors);
        self::assertSame('fifo.option_unmatched_close', $settlement->diagnostics[0]->code);
        self::assertSame($close->id(), $settlement->diagnostics[0]->rowId);
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

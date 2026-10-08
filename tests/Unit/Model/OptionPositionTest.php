<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Exception\InvalidRecordException;
use App\Fifo\InstrumentKind;
use App\Fifo\LotMethod;
use App\Fifo\PositionDirection;
use App\Fifo\PositionEffect;
use App\Fifo\Trade;
use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Options settle when the position closes - for a written option that is the
 * buy leg - and only an option's closing leg may be worth nothing.
 */
#[CoversClass(ClosedPosition::class)]
#[CoversClass(Trade::class)]
final class OptionPositionTest extends TestCase
{
    public function testAStockPositionKeepsItsIdentity(): void
    {
        // Precomputed before options existed: tombstones and stable IDs posted
        // by an open workbench must keep matching.
        self::assertSame('e062884c4e7cf5895fc7d08cf0829f07164989d45a7d70c4fe4685d65587ee55', self::stock()->fingerprint());
        self::assertSame('99e3c2c9bbb23b2ced20f5b1b211d5c8e38d446adf23a71885dab0d1afca273f', self::stockTrade()->id());
    }

    /**
     * Naming the lot (specific identification) records how it was chosen, but
     * the position's identity stays the one FIFO would have produced.
     */
    public function testTheLotMethodIsNotPartOfTheIdentity(): void
    {
        $named = new ClosedPosition(
            'ALFA CORP',
            'US',
            'USD',
            new DateTimeImmutable('2025-03-03'),
            Amount::of('1001.00', 'USD'),
            new DateTimeImmutable('2026-02-02'),
            Amount::of('1198.50', 'USD'),
            Decimal::of('10'),
            'f.csv',
            lotMethod: LotMethod::Specific,
        );

        self::assertSame(self::stock()->fingerprint(), $named->fingerprint());
    }

    public function testAnOptionTradeDoesNotShareAnIdentityWithTheSameStockRow(): void
    {
        $option = new Trade(
            'AAA',
            new DateTimeImmutable('2025-03-03 10:00:00'),
            Decimal::of('10'),
            Amount::of('1001.00', 'USD'),
            'auto:abc',
            'f.csv',
            null,
            1,
            false,
            null,
            '',
            'IBKR',
            Amount::of('1.00', 'USD'),
            null,
            '',
            'US000ALFA001@USD',
            InstrumentKind::Option,
            PositionEffect::Open,
        );

        self::assertNotSame(self::stockTrade()->id(), $option->id());
    }

    public function testAStockCannotBeShort(): void
    {
        $this->expectException(InvalidRecordException::class);
        $this->expectExceptionMessage('Krótka sprzedaż akcji');

        self::position('100', '120', InstrumentKind::Stock, PositionDirection::Short);
    }

    public function testAStockLegStillCannotBeZero(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::position('100', '0', InstrumentKind::Stock, PositionDirection::Long);
    }

    public function testAWrittenOptionThatExpiredClosesAtZero(): void
    {
        $position = self::position('0', '99', InstrumentKind::Option, PositionDirection::Short);

        self::assertTrue($position->isOption());
        self::assertTrue($position->isShort());
        self::assertSame('0', (string) $position->buyAmount->value());
    }

    public function testABoughtOptionThatExpiredClosesAtZero(): void
    {
        $position = self::position('250', '0', InstrumentKind::Option, PositionDirection::Long);

        self::assertSame('0', (string) $position->sellAmount->value());
    }

    public function testTheOpeningLegOfAnOptionCannotBeZero(): void
    {
        foreach ([['0', '10', PositionDirection::Long], ['10', '0', PositionDirection::Short]] as [$buy, $sell, $direction]) {
            try {
                self::position($buy, $sell, InstrumentKind::Option, $direction);
                self::fail('A zero opening leg was accepted for '.$direction->value);
            } catch (InvalidRecordException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAZeroClosingLegCarriesNoFee(): void
    {
        $this->expectException(InvalidRecordException::class);

        self::position('0', '99', InstrumentKind::Option, PositionDirection::Short, buyCommission: '0.50');
    }

    public function testAWrittenOptionClosesOnItsBuyLeg(): void
    {
        $position = self::position(
            '0',
            '99',
            InstrumentKind::Option,
            PositionDirection::Short,
            buyDate: '2026-01-16',
            sellDate: '2025-12-15',
        );

        self::assertSame('2025-12-15', $position->openDate()->format('Y-m-d'));
        self::assertSame('2026-01-16', $position->closeDate()->format('Y-m-d'));
        self::assertSame('2026-01-16', $position->revenueDate()->format('Y-m-d'));
        self::assertSame(2026, $position->taxYear());
        self::assertSame(['2026-01-16', '2025-12-15'], array_map(
            static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'),
            $position->conversionDates(),
        ));
    }

    public function testAStockClosesOnItsSellLegAsBefore(): void
    {
        $position = self::stock();

        self::assertFalse($position->isOption());
        self::assertSame('2026-02-02', $position->closeDate()->format('Y-m-d'));
        self::assertSame('2026-02-02', $position->revenueDate()->format('Y-m-d'));
        self::assertSame(2026, $position->taxYear());
    }

    public function testAnOptionHasItsOwnFingerprint(): void
    {
        $long = self::position('250', '400', InstrumentKind::Option, PositionDirection::Long);
        $short = self::position('250', '400', InstrumentKind::Option, PositionDirection::Short);

        self::assertNotSame($long->fingerprint(), $short->fingerprint());
        self::assertNotSame(self::position('250', '400', InstrumentKind::Stock, PositionDirection::Long)->fingerprint(), $long->fingerprint());
    }

    private static function stock(): ClosedPosition
    {
        return new ClosedPosition(
            'ALFA CORP',
            'US',
            'USD',
            new DateTimeImmutable('2025-03-03'),
            Amount::of('1001.00', 'USD'),
            new DateTimeImmutable('2026-02-02'),
            Amount::of('1198.50', 'USD'),
            Decimal::of('10'),
            'f.csv',
        );
    }

    private static function stockTrade(): Trade
    {
        return new Trade(
            'AAA',
            new DateTimeImmutable('2025-03-03 10:00:00'),
            Decimal::of('10'),
            Amount::of('1001.00', 'USD'),
            'auto:abc',
            'f.csv',
            null,
            1,
            false,
            null,
            '',
            'IBKR',
            Amount::of('1.00', 'USD'),
            null,
            '',
            'US000ALFA001@USD',
        );
    }

    private static function position(
        string $buy,
        string $sell,
        InstrumentKind $kind,
        PositionDirection $direction,
        ?string $buyCommission = null,
        string $buyDate = '2025-11-03',
        string $sellDate = '2026-02-10',
    ): ClosedPosition {
        return new ClosedPosition(
            'AAA 16JAN26 50 P',
            'US',
            'USD',
            new DateTimeImmutable($buyDate),
            Amount::of($buy, 'USD'),
            new DateTimeImmutable($sellDate),
            Amount::of($sell, 'USD'),
            Decimal::of('1'),
            'f.csv',
            buyCommission: null === $buyCommission ? null : Amount::of($buyCommission, 'USD'),
            kind: $kind,
            direction: $direction,
        );
    }
}

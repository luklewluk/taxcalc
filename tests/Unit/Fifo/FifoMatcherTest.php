<?php

declare(strict_types=1);

namespace App\Tests\Unit\Fifo;

use App\Fifo\FifoMatcher;
use App\Fifo\InstrumentDetails;
use App\Fifo\Trade;
use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FifoMatcher::class)]
final class FifoMatcherTest extends TestCase
{
    public function testWholeLotSellProducesOneMatch(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2024-03-01', '10', '1000.00'),
            self::sell('2024-06-01', '10', '1500.00'),
        ]);

        self::assertCount(1, $result->matches);
        self::assertSame([], $result->unmatchedSells);

        $match = $result->matches[0];
        self::assertSame('2024-03-01', $match->buyDate->format('Y-m-d'));
        self::assertSame('2024-06-01', $match->sellDate->format('Y-m-d'));
        self::assertSame('10', (string) $match->quantity);
        self::assertSame('1000.00', (string) $match->buyCost->value());
        self::assertSame('1500.00', (string) $match->sellProceeds->value());
    }

    public function testSellIsMatchedAgainstOldestBuysFirst(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-10', '5', '500.00'),
            self::buy('2024-02-10', '5', '700.00'),
            self::sell('2024-09-01', '7', '1400.00'),
        ]);

        self::assertCount(2, $result->matches);

        self::assertSame('2024-01-10', $result->matches[0]->buyDate->format('Y-m-d'));
        self::assertSame('5', (string) $result->matches[0]->quantity);
        self::assertSame('500.00', (string) $result->matches[0]->buyCost->value());

        self::assertSame('2024-02-10', $result->matches[1]->buyDate->format('Y-m-d'));
        self::assertSame('2', (string) $result->matches[1]->quantity);
        // 2/5 of 700.00
        self::assertSame('280.00', (string) $result->matches[1]->buyCost->value());
    }

    public function testInputOrderDoesNotMatterBecauseTradesAreSortedByDate(): void
    {
        $result = (new FifoMatcher())->match([
            self::sell('2024-09-01', '5', '900.00'),
            self::buy('2024-02-10', '5', '700.00'),
            self::buy('2024-01-10', '5', '500.00'),
        ]);

        self::assertCount(1, $result->matches);
        self::assertSame('2024-01-10', $result->matches[0]->buyDate->format('Y-m-d'));
        self::assertSame('500.00', (string) $result->matches[0]->buyCost->value());
    }

    public function testProratedPartsAlwaysSumBackToTheOriginalLotTotal(): void
    {
        // 100.00 split across three sells of 1/3 each must not lose a grosz:
        // naive proration would give 33.33 * 3 = 99.99.
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-01', '3', '100.00'),
            self::sell('2024-02-01', '1', '40.00'),
            self::sell('2024-03-01', '1', '40.00'),
            self::sell('2024-04-01', '1', '40.00'),
        ]);

        self::assertCount(3, $result->matches);

        $total = Amount::zero('USD');
        foreach ($result->matches as $match) {
            $total = $total->plus($match->buyCost);
        }

        self::assertSame(0, $total->compareTo(Amount::of('100.00', 'USD')), (string) $total);
    }

    public function testFractionalSharesAreHandledExactly(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-01', '0.5432', '123.4567'),
            self::sell('2024-02-01', '0.5432', '200.0000'),
        ]);

        self::assertCount(1, $result->matches);
        self::assertSame('0.5432', (string) $result->matches[0]->quantity);
        self::assertSame('123.4567', (string) $result->matches[0]->buyCost->value());
    }

    public function testBuysFromPreviousYearsRemainAvailableForLaterSales(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2021-05-04', '10', '1000.00'),
            self::sell('2025-01-20', '10', '3000.00'),
        ]);

        self::assertCount(1, $result->matches);
        self::assertSame('2021-05-04', $result->matches[0]->buyDate->format('Y-m-d'));
        self::assertSame('2025-01-20', $result->matches[0]->sellDate->format('Y-m-d'));
    }

    public function testSellWithoutAnyBuyIsReportedInsteadOfThrowing(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-01', '2', '100.00'),
            self::sell('2024-02-01', '5', '400.00'),
        ]);

        self::assertCount(1, $result->matches);
        self::assertCount(1, $result->unmatchedSells);

        $unmatched = $result->unmatchedSells[0];
        self::assertSame('AAA', $unmatched->symbol);
        self::assertSame('3', (string) $unmatched->quantity);
        self::assertSame('2024-02-01', $unmatched->date->format('Y-m-d'));
    }

    public function testRemainingOpenBuysAreNotReportedAsPositions(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-01', '10', '1000.00'),
            self::sell('2024-02-01', '4', '500.00'),
        ]);

        self::assertCount(1, $result->matches);
        self::assertSame('4', (string) $result->matches[0]->quantity);
        self::assertSame('400.00', (string) $result->matches[0]->buyCost->value());
    }

    public function testExecutionPricesAreCarriedUnchangedAcrossPartialFifoMatches(): void
    {
        $buy = new Trade(
            'AAA',
            new DateTimeImmutable('2024-01-01'),
            Decimal::of('10'),
            Amount::of('1001.00', 'EUR'),
            unitPrice: Amount::of('12.345600', 'USD'),
        );
        $sell = new Trade(
            'AAA',
            new DateTimeImmutable('2024-06-01'),
            Decimal::of('-6'),
            Amount::of('899.00', 'EUR'),
            unitPrice: Amount::of('18.9000', 'USD'),
        );

        $result = (new FifoMatcher())->match([$buy, $sell]);

        self::assertCount(1, $result->matches);
        self::assertSame('12.345600', (string) $result->matches[0]->buyUnitPrice?->value());
        self::assertSame('USD', $result->matches[0]->buyUnitPrice?->currency());
        self::assertSame('18.9000', (string) $result->matches[0]->sellUnitPrice?->value());
        self::assertSame('600.60', (string) $result->matches[0]->buyCost->value());
        self::assertSame('899.00', (string) $result->matches[0]->sellProceeds->value());
    }

    public function testMissingExecutionPricesRemainNullInFifo(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-01', '1', '10.00'),
            self::sell('2024-06-01', '1', '15.00'),
        ]);

        self::assertNull($result->matches[0]->buyUnitPrice);
        self::assertNull($result->matches[0]->sellUnitPrice);
    }

    public function testDifferentSymbolsAreMatchedIndependently(): void
    {
        $result = (new FifoMatcher())->match([
            new Trade('AAA', new DateTimeImmutable('2024-01-01'), Decimal::of('1'), Amount::of('10.00', 'USD'), null),
            new Trade('BBB', new DateTimeImmutable('2024-01-02'), Decimal::of('1'), Amount::of('20.00', 'USD'), null),
            new Trade('BBB', new DateTimeImmutable('2024-03-02'), Decimal::of('-1'), Amount::of('25.00', 'USD'), null),
            new Trade('AAA', new DateTimeImmutable('2024-03-01'), Decimal::of('-1'), Amount::of('15.00', 'USD'), null),
        ]);

        self::assertCount(2, $result->matches);

        $symbols = array_map(static fn ($m) => $m->symbol, $result->matches);
        sort($symbols);
        self::assertSame(['AAA', 'BBB'], $symbols);
    }

    public function testSameDayBuyAndSellKeepsFileOrder(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-01', '1', '10.00'),
            self::sell('2024-01-01', '1', '12.00'),
        ]);

        self::assertCount(1, $result->matches);
        self::assertSame('10.00', (string) $result->matches[0]->buyCost->value());
        self::assertSame('12.00', (string) $result->matches[0]->sellProceeds->value());
    }

    /**
     * The matching key is the symbol alone, so display metadata - a product name
     * that changed between the buy and the sell - must not split a position.
     */
    public function testDisplayMetadataDoesNotTakePartInMatching(): void
    {
        $result = (new FifoMatcher())->match([
            self::trade('2024-03-01', '10', '1000.00', 'order-1', new InstrumentDetails('ALFA CORP', 'US')),
            self::trade('2024-06-01', '-10', '1500.00', 'order-2', new InstrumentDetails('ALFA GROUP PLC', 'US')),
        ]);

        self::assertCount(1, $result->matches);

        // The sell leg is the later one, so its name is the current one.
        self::assertSame('ALFA GROUP PLC', $result->matches[0]->instrument()?->displayName);
        self::assertSame('ALFA CORP', $result->matches[0]->buyInstrument?->displayName);
        self::assertSame('US', $result->matches[0]->instrument()?->countryCode);
    }

    /**
     * Two identical lots closed by one sell are two closed positions. Every
     * other field of those matches agrees - including the broker's identifiers
     * when those name an order rather than a fill - so the ordinal is the only
     * thing keeping them apart when duplicates are dropped later.
     */
    public function testIdenticalMatchesGetDistinctLineageKeys(): void
    {
        $result = (new FifoMatcher())->match([
            self::trade('2024-03-01', '5', '500.00', 'order-1'),
            self::trade('2024-03-01', '5', '500.00', 'order-1'),
            self::trade('2024-06-01', '-10', '1500.00', 'order-2'),
        ]);

        self::assertCount(2, $result->matches);

        $keys = array_map(static fn ($m): ?string => $m->lineageKey(), $result->matches);
        self::assertNotNull($keys[0]);
        self::assertNotNull($keys[1]);
        self::assertNotSame($keys[0], $keys[1]);
    }

    public function testLineageStaysUnavailableWhenTheSourceGivesNoIds(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2024-03-01', '10', '1000.00'),
            self::sell('2024-06-01', '10', '1500.00'),
        ]);

        // Without both IDs the caller has to fall back to a content fingerprint,
        // which is what protects a re-uploaded statement from being counted twice.
        self::assertNull($result->matches[0]->lineageKey());
    }

    /**
     * A share of a lot is a ratio, not a six-digit decimal: at six digits, one
     * third of a billion loses 333.33 per slice. The proration is therefore
     * computed exactly and rounded once, to the scale the amount already has.
     */
    public function testAHugeLotIsProratedExactlyRatherThanThroughATruncatedShare(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-01', '3', '1000000000.00'),
            self::sell('2024-02-01', '1', '400000000.00'),
            self::sell('2024-03-01', '1', '400000000.00'),
            self::sell('2024-04-01', '1', '400000000.00'),
        ]);

        self::assertCount(3, $result->matches);

        $costs = array_map(static fn ($m): string => (string) $m->buyCost->value(), $result->matches);
        self::assertSame(['333333333.33', '333333333.33', '333333333.34'], $costs);
    }

    public function testProratedSlicesOfAHugeLotStillSumBackToTheLotTotal(): void
    {
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-01', '7', '1000000000.00'),
            self::sell('2024-02-01', '1', '200000000.00'),
            self::sell('2024-03-01', '2', '400000000.00'),
            self::sell('2024-04-01', '4', '800000000.00'),
        ]);

        $total = Amount::zero('USD');
        foreach ($result->matches as $match) {
            $total = $total->plus($match->buyCost);
        }

        self::assertSame(0, $total->compareTo(Amount::of('1000000000.00', 'USD')), (string) $total);
    }

    public function testFractionalQuantitiesProrateExactly(): void
    {
        // 0.5432 of a 1.6296-share lot is exactly one third of it.
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-01', '1.6296', '100.00'),
            self::sell('2024-02-01', '0.5432', '60.00'),
            self::sell('2024-03-01', '0.5432', '60.00'),
            self::sell('2024-04-01', '0.5432', '60.00'),
        ]);

        $costs = array_map(static fn ($m): string => (string) $m->buyCost->value(), $result->matches);
        self::assertSame(['33.33', '33.33', '33.34'], $costs);
    }

    public function testAPartialSellIsProratedExactlyAcrossTheLotsItTouches(): void
    {
        // One sell of 3 units for 1,000,000,000.00 spread over three lots of 1.
        $result = (new FifoMatcher())->match([
            self::buy('2024-01-01', '1', '10.00'),
            self::buy('2024-01-02', '1', '10.00'),
            self::buy('2024-01-03', '1', '10.00'),
            self::sell('2024-06-01', '3', '1000000000.00'),
        ]);

        self::assertCount(3, $result->matches);

        $proceeds = array_map(static fn ($m): string => (string) $m->sellProceeds->value(), $result->matches);
        self::assertSame(['333333333.33', '333333333.33', '333333333.34'], $proceeds);
    }

    /**
     * A position's lineage must not move when an unrelated instrument is added
     * to the same upload: the fingerprint derived from it is what decides whether
     * a record is a duplicate, and it has to be stable per FIFO queue.
     */
    public function testLineageOfAPositionDoesNotDependOnOtherInstruments(): void
    {
        $alone = (new FifoMatcher())->match([
            self::symbolTrade('AAA', '2024-01-01', '1', '10.00', 'b1'),
            self::symbolTrade('AAA', '2024-06-01', '-1', '15.00', 's1'),
        ]);

        $withNeighbour = (new FifoMatcher())->match([
            self::symbolTrade('BBB', '2024-01-01', '1', '20.00', 'b2'),
            self::symbolTrade('BBB', '2024-06-01', '-1', '25.00', 's2'),
            self::symbolTrade('AAA', '2024-01-01', '1', '10.00', 'b1'),
            self::symbolTrade('AAA', '2024-06-01', '-1', '15.00', 's1'),
        ]);

        $aaa = array_values(array_filter(
            $withNeighbour->matches,
            static fn ($m): bool => 'AAA' === $m->symbol,
        ));

        self::assertCount(1, $aaa);
        self::assertSame($alone->matches[0]->lineageKey(), $aaa[0]->lineageKey());
    }

    public function testLineageDoesNotDependOnTheOrderSymbolsArriveIn(): void
    {
        $first = (new FifoMatcher())->match([
            self::symbolTrade('AAA', '2024-01-01', '1', '10.00', 'b1'),
            self::symbolTrade('AAA', '2024-06-01', '-1', '15.00', 's1'),
            self::symbolTrade('BBB', '2024-01-01', '1', '20.00', 'b2'),
            self::symbolTrade('BBB', '2024-06-01', '-1', '25.00', 's2'),
        ]);

        $reordered = (new FifoMatcher())->match([
            self::symbolTrade('BBB', '2024-01-01', '1', '20.00', 'b2'),
            self::symbolTrade('BBB', '2024-06-01', '-1', '25.00', 's2'),
            self::symbolTrade('AAA', '2024-01-01', '1', '10.00', 'b1'),
            self::symbolTrade('AAA', '2024-06-01', '-1', '15.00', 's1'),
        ]);

        $keys = static function (array $matches): array {
            $result = [];
            foreach ($matches as $match) {
                $result[] = $match->lineageKey();
            }
            sort($result);

            return $result;
        };

        self::assertSame($keys($first->matches), $keys($reordered->matches));
    }

    private static function symbolTrade(
        string $symbol,
        string $date,
        string $quantity,
        string $total,
        ?string $externalId = null,
    ): Trade {
        return new Trade(
            $symbol,
            new DateTimeImmutable($date),
            Decimal::of($quantity),
            Amount::of($total, 'USD'),
            $externalId,
        );
    }

    private static function trade(
        string $date,
        string $quantity,
        string $total,
        ?string $externalId = null,
        ?InstrumentDetails $instrument = null,
    ): Trade {
        return new Trade(
            'AAA',
            new DateTimeImmutable($date),
            Decimal::of($quantity),
            Amount::of($total, 'USD'),
            $externalId,
            '',
            $instrument,
        );
    }

    private static function buy(string $date, string $quantity, string $total): Trade
    {
        return new Trade('AAA', new DateTimeImmutable($date), Decimal::of($quantity), Amount::of($total, 'USD'), null);
    }

    private static function sell(string $date, string $quantity, string $total): Trade
    {
        return new Trade(
            'AAA',
            new DateTimeImmutable($date),
            Decimal::of($quantity)->negated(),
            Amount::of($total, 'USD'),
            null,
        );
    }
}

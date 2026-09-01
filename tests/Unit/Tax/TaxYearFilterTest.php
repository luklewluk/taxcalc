<?php

declare(strict_types=1);

namespace App\Tests\Unit\Tax;

use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Money\Amount;
use App\Tax\TaxYearFilter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxYearFilter::class)]
final class TaxYearFilterTest extends TestCase
{
    public function testPositionsAreSelectedByTheYearOfTheSaleNotTheBuy(): void
    {
        $filter = new TaxYearFilter();

        $kept = self::position('2021-05-04', '2025-01-20');
        $excluded = self::position('2024-01-01', '2024-06-01');

        $result = $filter->positionsForYear([$kept, $excluded], 2025);

        self::assertCount(1, $result);
        self::assertSame('2021-05-04', $result[0]->buyDate->format('Y-m-d'));
    }

    public function testBoundaryDatesBelongToTheYearTheyFallIn(): void
    {
        $filter = new TaxYearFilter();

        $lastDay = self::position('2020-01-01', '2024-12-31');
        $firstDay = self::position('2020-01-01', '2025-01-01');

        self::assertCount(1, $filter->positionsForYear([$lastDay, $firstDay], 2024));
        self::assertCount(1, $filter->positionsForYear([$lastDay, $firstDay], 2025));
    }

    public function testDividendsAreSelectedByPaymentDate(): void
    {
        $filter = new TaxYearFilter();

        $result = $filter->dividendsForYear(
            [self::dividend('2024-12-31'), self::dividend('2025-01-02')],
            2025,
        );

        self::assertCount(1, $result);
        self::assertSame('2025-01-02', $result[0]->date->format('Y-m-d'));
    }

    public function testAvailableYearsAreDerivedFromRealisedIncomeOnly(): void
    {
        $filter = new TaxYearFilter();

        $years = $filter->availableYears(
            [self::position('2019-01-01', '2023-06-01'), self::position('2020-01-01', '2025-06-01')],
            [self::dividend('2024-05-05')],
        );

        // Descending, most recent first, and 2019/2020 (buys only) are absent.
        self::assertSame([2025, 2024, 2023], $years);
    }

    public function testAvailableYearsIsEmptyWithoutData(): void
    {
        self::assertSame([], (new TaxYearFilter())->availableYears([], []));
    }

    public function testCountsWhatWasLeftOutSoTheUiCanExplainTheDifference(): void
    {
        $filter = new TaxYearFilter();

        $positions = [self::position('2020-01-01', '2024-06-01'), self::position('2020-01-01', '2025-06-01')];
        $dividends = [self::dividend('2024-05-05'), self::dividend('2025-05-05'), self::dividend('2025-08-05')];

        self::assertSame(1, $filter->excludedPositionCount($positions, 2025));
        self::assertSame(1, $filter->excludedDividendCount($dividends, 2025));
    }

    private static function position(string $buyDate, string $sellDate): ClosedPosition
    {
        return new ClosedPosition(
            'AAA',
            'US',
            'USD',
            new DateTimeImmutable($buyDate),
            Amount::of('100.00', 'USD'),
            new DateTimeImmutable($sellDate),
            Amount::of('150.00', 'USD'),
            null,
            'test',
        );
    }

    private static function dividend(string $date): Dividend
    {
        return new Dividend(
            'AAA',
            'US',
            'USD',
            new DateTimeImmutable($date),
            Amount::of('10.00', 'USD'),
            Amount::of('1.50', 'USD'),
            'test',
        );
    }
}

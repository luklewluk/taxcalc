<?php

declare(strict_types=1);

namespace App\Tests\Unit\Report;

use App\CurrencyRate\ExchangeInterface;
use App\CurrencyRate\NbpExchange;
use App\Fifo\InstrumentKind;
use App\Fifo\PositionDirection;
use App\Money\Decimal;
use App\Tests\Support\DateGatedNbpRateProvider;
use App\Model\AccountFee;
use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Money\Amount;
use App\Report\TaxReportBuilder;
use App\Tax\DividendTaxCalculator;
use App\Tax\StockTaxCalculator;
use App\Tax\TaxRates;
use App\Tax\TaxYearFilter;
use App\Tests\Support\FixedExchange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaxReportBuilder::class)]
final class TaxReportBuilderTest extends TestCase
{
    public function testOnlyIncomeRealisedInTheChosenYearIsReported(): void
    {
        $report = $this->build(
            [
                // sold in 2025 - excluded from the 2024 report
                self::position('2020-01-01', '2025-06-01'),
                // sold in 2024 - included, even though bought years earlier
                self::position('2019-03-03', '2024-06-01'),
            ],
            [self::dividend('2024-05-05'), self::dividend('2023-05-05')],
            2024,
        );

        self::assertSame(2024, $report->taxYear);
        self::assertCount(1, $report->stock->positions);
        self::assertSame('2019-03-03', $report->stock->positions[0]->position->buyDate->format('Y-m-d'));
        self::assertCount(1, $report->dividends->dividends);

        self::assertSame(1, $report->excludedPositions);
        self::assertSame(1, $report->excludedDividends);
    }

    public function testOneUnavailableRateInvalidatesTheWholeReportInsteadOfReturningPartialTax(): void
    {
        // JPY is not in the offline fixture, so its rate lookup fails.
        $report = $this->build(
            [
                self::position('2024-01-01', '2024-06-01', 'USD'),
                self::position('2024-01-01', '2024-07-01', 'JPY'),
            ],
            [],
            2024,
        );

        self::assertCount(0, $report->stock->positions);
        self::assertSame('0.00', (string) $report->totalTaxConservative->value());
        self::assertSame('0.00', (string) $report->totalTaxNsa->value());
        self::assertNotEmpty($report->errors);
        self::assertStringContainsString('JPY', implode(' ', $report->errors));
    }

    public function testAWrittenOptionNeedsTheRatesOfBothItsOpeningAndClosingDays(): void
    {
        $option = new ClosedPosition(
            'AAA 16JAN26 50 P',
            'US',
            'USD',
            new DateTimeImmutable('2026-01-16'),
            Amount::of('0', 'USD'),
            new DateTimeImmutable('2025-12-15'),
            Amount::of('99', 'USD'),
            Decimal::of('1'),
            'f.csv',
            sellCommission: Amount::of('1', 'USD'),
            kind: InstrumentKind::Option,
            direction: PositionDirection::Short,
        );

        foreach (['2026-01-16', '2025-12-15'] as $missing) {
            $exchange = new NbpExchange(new DateGatedNbpRateProvider([$missing]));
            $report = self::builder($exchange)->build([$option], [], 2026);

            self::assertSame([], $report->stock->positions, $missing);
            self::assertStringContainsString('2026-01-16', implode(' ', $report->errors), 'The message names the closing day.');
        }

        $report = self::builder(new NbpExchange(new DateGatedNbpRateProvider([])))->build([$option], [], 2026);
        self::assertCount(1, $report->stock->positions);
    }

    public function testDividendWarningsSurfaceOnTheReport(): void
    {
        $report = $this->build([], [self::dividend('2024-05-05', 'ZZ')], 2024);

        self::assertNotEmpty($report->warnings);
        self::assertStringContainsString('ZZ', implode(' ', $report->warnings));
    }

    public function testBlankCountryInvalidatesTheWholeReport(): void
    {
        $position = new ClosedPosition(
            'AAA',
            '',
            'USD',
            new DateTimeImmutable('2024-01-01'),
            Amount::of('100.00', 'USD'),
            new DateTimeImmutable('2024-06-01'),
            Amount::of('150.00', 'USD'),
            null,
            'test',
        );

        $report = $this->build([$position], [], 2024);

        self::assertSame([], $report->stock->positions);
        self::assertNotEmpty($report->errors);
        self::assertMatchesRegularExpression('/kraj/iu', implode(' ', $report->errors));
    }

    public function testCombinedTaxIsTheSumOfBothParts(): void
    {
        $report = $this->build(
            [self::position('2024-01-01', '2024-06-01')],
            [self::dividend('2024-05-05')],
            2024,
        );

        // stock: (150-100)*4 = 200 PLN income -> 38.00
        // dividend: 10 USD * 4 = 40 PLN gross -> 7.60 PL tax, 1.50*4 = 6.00 credited -> 1.60
        self::assertSame('38.00', (string) $report->stock->tax->value());
        self::assertSame('1.60', (string) $report->dividends->conservative->taxDue->value());
        self::assertSame('39.60', (string) $report->totalTaxConservative->value());
        self::assertSame('40', (string) $report->totalTaxConservativeRounded->value());
    }

    public function testEmptyYearProducesAnEmptyButValidReport(): void
    {
        $report = $this->build([], [], 2024);

        self::assertTrue($report->isEmpty());
        self::assertSame('0.00', (string) $report->totalTaxConservative->value());
    }

    public function testAccountFeeUsesValueDateYearAndPreviousDayRate(): void
    {
        $fee = new AccountFee(
            'DEGIRO Exchange Connection Fee',
            'Połączenie z giełdą',
            new DateTimeImmutable('2024-12-31'),
            'EUR',
            Amount::of('2.50', 'EUR'),
            false,
            'account.csv',
            'fee-1',
        );

        $report = self::builder(FixedExchange::create())->build([], [], 2024, [$fee]);

        self::assertSame('10.75', (string) $report->stock->totalCost->value());
        self::assertSame('2024-12-30', $report->stock->accountingFees[0]->exchanged->rateDate?->format('Y-m-d'));
        self::assertSame(0, $report->excludedFees);
    }

    public function testFullyReversedManualFeeGroupIsOmittedWithAWarning(): void
    {
        $fees = [
            new AccountFee('fee', 'account', new DateTimeImmutable('2024-02-01'), 'USD', Amount::of('2', 'USD'), false, 'manual', 'fee-1'),
            new AccountFee('refund', 'account', new DateTimeImmutable('2024-02-02'), 'USD', Amount::of('2', 'USD'), true, 'manual', 'fee-2'),
        ];

        $report = self::builder(FixedExchange::create())->build([], [], 2024, $fees);

        self::assertSame([], $report->errors);
        self::assertSame([], $report->stock->accountingFees);
        self::assertStringContainsString('wyzerowan', mb_strtolower(implode(' ', $report->warnings)));
    }

    public function testRefundAboveManualFeesFailsClosed(): void
    {
        $fees = [
            new AccountFee('fee', 'account', new DateTimeImmutable('2024-02-01'), 'USD', Amount::of('2', 'USD'), false, 'manual', 'fee-1'),
            new AccountFee('refund', 'account', new DateTimeImmutable('2024-02-02'), 'USD', Amount::of('3', 'USD'), true, 'manual', 'fee-2'),
        ];

        $report = self::builder(FixedExchange::create())->build([], [], 2024, $fees);

        self::assertNotEmpty($report->errors);
        self::assertSame([], $report->stock->accountingFees);
        self::assertSame('0.00', (string) $report->stock->totalCost->value());
    }

    /**
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     */
    private function build(array $positions, array $dividends, int $year): \App\Report\TaxReport
    {
        return self::builder(FixedExchange::create())->build($positions, $dividends, $year);
    }

    private static function builder(ExchangeInterface $exchange): TaxReportBuilder
    {
        return new TaxReportBuilder(
            new TaxYearFilter(),
            new StockTaxCalculator($exchange),
            new DividendTaxCalculator($exchange, new TaxRates()),
            $exchange,
        );
    }

    private static function position(string $buyDate, string $sellDate, string $currency = 'USD'): ClosedPosition
    {
        return new ClosedPosition(
            'AAA',
            'US',
            $currency,
            new DateTimeImmutable($buyDate),
            Amount::of('100.00', $currency),
            new DateTimeImmutable($sellDate),
            Amount::of('150.00', $currency),
            null,
            'test',
        );
    }

    private static function dividend(string $date, string $country = 'US'): Dividend
    {
        return new Dividend(
            'AAA',
            $country,
            'USD',
            new DateTimeImmutable($date),
            Amount::of('10.00', 'USD'),
            Amount::of('1.50', 'USD'),
            'test',
        );
    }
}

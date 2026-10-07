<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Ledger;

use App\CurrencyRate\NbpExchange;
use App\Fifo\FifoMatcher;
use App\Fifo\InstrumentDetails;
use App\Fifo\InstrumentKind;
use App\Fifo\PositionDirection;
use App\Fifo\PositionEffect;
use App\Fifo\Trade;
use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Money\Decimal;
use App\Tax\StockTaxCalculator;
use App\Tests\Support\CountingNbpRateProvider;
use App\Tests\Support\FixedExchange;
use App\Tests\Support\FixedNbpRateProvider;
use App\Web\Diagnostic;
use App\Web\Ledger\LedgerEntry;
use App\Web\Ledger\TradeLedger;
use App\Web\Ledger\TradeLedgerBuilder;
use App\Web\RowFormMapper;
use App\Web\SettlementResult;
use App\Web\WorkbenchCalculator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Transakcje tab: rows grouped by kind and FIFO queue, each trade with
 * everything FIFO derived from it - for every year, not only the settled one.
 */
#[CoversClass(TradeLedgerBuilder::class)]
#[CoversClass(TradeLedger::class)]
final class TradeLedgerBuilderTest extends TestCase
{
    private const string PUT = 'AAA 16JAN26 50 P';

    public function testStocksComeBeforeOptionsAndAnOptionSectionOnlyWhenThereAreOptions(): void
    {
        $ledger = $this->ledger([
            self::option('-1', '99', '1', PositionEffect::Open, '2025-12-15'),
            self::stock('AAA', '2024-01-02', '10', '1000.00'),
            self::option('1', '0', '0', PositionEffect::Close, '2026-01-16'),
        ]);

        self::assertSame(['stocks', 'options'], array_map(static fn ($section): string => $section->key, $ledger->sections));
        self::assertSame(self::PUT, $ledger->sections[1]->groups[0]->title);

        $stocksOnly = $this->ledger([self::stock('AAA', '2024-01-02', '10', '1000.00')]);
        self::assertSame(['stocks'], array_map(static fn ($section): string => $section->key, $stocksOnly->sections));
    }

    public function testOneGroupPerQueueWithTheMostRecentName(): void
    {
        $ledger = $this->ledger([
            self::stock('AAA', '2024-01-02', '10', '1000.00', name: 'ALFA CORP'),
            self::stock('BBB', '2024-01-03', '1', '100.00'),
            self::stock('AAA', '2024-06-03', '-10', '1500.00', name: 'ALFA CORPORATION'),
        ]);

        $groups = $ledger->sections[0]->groups;
        self::assertCount(2, $groups);
        self::assertSame('ALFA CORPORATION', $groups[0]->title);
        self::assertSame('IBKR', $groups[0]->broker);
        self::assertSame('AAA@USD', $groups[0]->pool);
        self::assertSame([0, 2], array_map(static fn (LedgerEntry $entry): int => $entry->index, $groups[0]->entries));
    }

    public function testAQueueMixingStocksAndOptionsStaysWholeAndIsFlagged(): void
    {
        $option = new Trade('AAA', new DateTimeImmutable('2024-02-01 10:00:00'), Decimal::of('1'), Amount::of('50.00', 'USD'),
            'auto:o', 'as.csv', new InstrumentDetails('AAA', 'US'), broker: 'IBKR', fifoPool: 'AAA@USD',
            kind: InstrumentKind::Option, effect: PositionEffect::Open);

        $ledger = $this->ledger([self::stock('AAA', '2024-01-02', '10', '1000.00'), $option]);

        self::assertCount(1, $ledger->sections);
        self::assertTrue($ledger->sections[0]->groups[0]->mixedKinds);
        self::assertCount(2, $ledger->sections[0]->groups[0]->entries);
    }

    public function testRowsAreChronologicalAndTiesKeepTheirPostedOrder(): void
    {
        $ledger = $this->ledger([
            self::stock('AAA', '2024-03-01', '-3', '450.00'),
            self::stock('AAA', '2024-01-02', '2', '200.00', time: '10:00:00'),
            self::stock('AAA', '2024-01-02', '1', '100.00', time: '10:00:00'),
            self::stock('AAA', '2024-01-02', '5', '500.00', time: '09:00:00'),
        ]);

        self::assertSame([3, 1, 2, 0], array_map(
            static fn (LedgerEntry $entry): int => $entry->index,
            $ledger->sections[0]->groups[0]->entries,
        ));
    }

    public function testPostingTheRowsInLedgerOrderLeavesEveryPositionUnchanged(): void
    {
        $trades = [
            self::stock('AAA', '2024-03-01', '-3', '450.00'),
            self::stock('BBB', '2024-01-05', '4', '400.00'),
            self::stock('AAA', '2024-01-02', '2', '200.00', time: '10:00:00', externalId: 'x1'),
            self::stock('AAA', '2024-01-02', '1', '100.00', time: '10:00:00', externalId: 'x2'),
            self::stock('BBB', '2024-02-05', '-4', '480.00'),
            self::stock('AAA', '2024-01-02', '5', '500.00', time: '09:00:00'),
            self::stock('AAA', '2024-03-01', '-5', '800.00'),
        ];
        $mapper = new RowFormMapper();
        $rows = $mapper->mapTrades(array_map($mapper->tradeToForm(...), $trades))->rows;
        $before = $this->settle($rows);

        $ledger = $this->builder()->build($rows, [], $before);
        $reordered = array_map(static fn (LedgerEntry $entry): array => $entry->row, $ledger->entries());
        self::assertNotSame($rows, $reordered, 'The ledger does reorder the rows.');

        $after = $this->settle($reordered);

        self::assertSame(self::fingerprints($before), self::fingerprints($after));
    }

    public function testASaleShowsTheLotItClosedInPlnWithTheSellFeeAddedBack(): void
    {
        $buy = self::stock('AAA', '2024-01-02', '10', '1000.00');
        $sell = self::stock('AAA', '2024-06-03', '-10', '1499.00', commission: '1.00');

        $ledger = $this->ledger([$buy, $sell]);
        $detail = $ledger->entry($sell->id())?->detail;

        self::assertNotNull($detail);
        self::assertCount(1, $detail->closes);
        self::assertSame([], $detail->closedBy);

        $close = $detail->closes[0];
        self::assertSame($buy->id(), $close->counterpartId);
        self::assertSame('2024-01-02', $close->counterpart['date'] ?? null);
        self::assertNotNull($close->calculated);
        self::assertSame('4000.00', (string) $close->calculated->cost->pln->value());
        self::assertSame('6000.00', (string) $close->calculated->revenue->pln->value());
        self::assertSame('2024-06-02', $close->calculated->revenue->rateDate?->format('Y-m-d'));
        self::assertSame('4.00', (string) $close->calculated->disposalCost?->pln->value());
        self::assertSame('4004.00', (string) $close->calculated->totalCost()->value());

        self::assertSame(2024, $detail->taxYear);
        self::assertSame('1996.00', (string) $detail->income?->value());
        self::assertSame('379.2400', (string) $detail->informationalTax?->value());
        self::assertNull($detail->openQuantity);
    }

    public function testABuySoldInTwoYearsShowsBothAndWhatIsStillHeld(): void
    {
        $buy = self::stock('AAA', '2024-01-02', '10', '1000.00');

        $ledger = $this->ledger([
            $buy,
            self::stock('AAA', '2024-05-02', '-3', '450.00'),
            self::stock('AAA', '2025-02-03', '-4', '800.00'),
        ]);
        $detail = $ledger->entry($buy->id())?->detail;

        self::assertNotNull($detail);
        self::assertSame([2024, 2025], array_map(
            static fn ($match): ?int => $match->position?->taxYear(),
            $detail->closedBy,
        ));
        self::assertNotNull($detail->closedBy[0]->calculated);
        self::assertNotNull($detail->closedBy[1]->calculated);
        self::assertSame('3', (string) $detail->openQuantity);
        self::assertNull($detail->taxYear, 'A purchase is not taxed itself.');

        $group = $ledger->sections[0]->groups[0];
        self::assertSame('3', (string) $group->openQuantity);
        self::assertFalse($group->isClosed());
    }

    public function testAWrittenOptionSettlesOnTheClosingBuy(): void
    {
        $write = self::option('-1', '99', '1', PositionEffect::Open, '2025-12-15');
        $expiry = self::option('1', '0', '0', PositionEffect::Close, '2026-01-16');

        $ledger = $this->ledger([$write, $expiry]);

        $closing = $ledger->entry($expiry->id())?->detail;
        self::assertNotNull($closing);
        self::assertCount(1, $closing->closes);
        self::assertSame(2026, $closing->taxYear);
        self::assertSame($write->id(), $closing->closes[0]->counterpartId);

        $opening = $ledger->entry($write->id())?->detail;
        self::assertNotNull($opening);
        self::assertCount(1, $opening->closedBy);
        self::assertNull($opening->taxYear);

        $group = $ledger->sections[0]->groups[0];
        self::assertSame(PositionDirection::Short, $group->direction);
        self::assertTrue($group->isClosed());
    }

    public function testAnOpenWrittenOptionIsReportedShort(): void
    {
        $write = self::option('-2', '198', '2', PositionEffect::Open, '2025-12-15');

        $ledger = $this->ledger([$write]);

        self::assertSame('2', (string) $ledger->entry($write->id())?->detail?->openQuantity);
        self::assertSame(PositionDirection::Short, $ledger->entry($write->id())?->detail?->openDirection);
        self::assertSame(PositionDirection::Short, $ledger->sections[0]->groups[0]->direction);
    }

    public function testASaleWithoutAPurchaseSaysWhatIsMissing(): void
    {
        $orphan = self::stock('AAA', '2025-02-27', '-31', '15000.00');

        $ledger = $this->ledger([$orphan]);
        $detail = $ledger->entry($orphan->id())?->detail;

        self::assertNotNull($detail);
        self::assertSame('31', (string) $detail->unmatchedQuantity);
        self::assertStringContainsString('wcześniejszy rok', implode(' ', $detail->notices));
    }

    public function testAMissingRateStopsOnlyThatCurrencyAfterAFewTries(): void
    {
        $provider = new CountingNbpRateProvider(new FixedNbpRateProvider());
        $trades = [self::stock('AAA', '2024-01-02', '1', '100.00'), self::stock('AAA', '2024-02-02', '-1', '120.00')];
        foreach (['03', '04', '05', '06', '07'] as $month) {
            $trades[] = self::stock('SEKCO', '2024-01-02', '1', '100.00', currency: 'SEK', time: '1'.$month[1].':00:00');
            $trades[] = self::stock('SEKCO', '2024-'.$month.'-02', '-1', '120.00', currency: 'SEK');
        }

        $ledger = $this->ledger($trades, new TradeLedgerBuilder(new StockTaxCalculator(new NbpExchange($provider))));

        $unavailable = [];
        foreach ($ledger->entries() as $entry) {
            foreach ($entry->detail->closes ?? [] as $close) {
                $unavailable[$close->match->symbol][] = $close->unavailable;
            }
        }

        self::assertSame([null], $unavailable['AAA']);
        self::assertSame(['rate', 'rate', 'rate', 'rate', 'rate'], $unavailable['SEKCO']);
        self::assertSame(TradeLedgerBuilder::MAX_RATE_FAILURES_PER_CURRENCY, $provider->calls['SEK']);
        self::assertTrue($ledger->ratesIncomplete);
    }

    public function testTheTimeBudgetLeavesOlderPositionsForTheNextRender(): void
    {
        $times = [0.0, 0.0, 11.0];
        $clock = static function () use (&$times): float {
            return array_shift($times) ?? 99.0;
        };
        $old = self::stock('AAA', '2023-05-02', '-1', '120.00');
        $recent = self::stock('AAA', '2025-05-02', '-1', '130.00');

        $ledger = $this->ledger([
            self::stock('AAA', '2023-01-02', '2', '200.00'),
            $old,
            $recent,
        ], new TradeLedgerBuilder(new StockTaxCalculator(FixedExchange::create()), rateBudgetSeconds: 10.0, clock: $clock));

        self::assertNotNull($ledger->entry($recent->id())?->detail?->closes[0]->calculated, 'The latest close goes first.');
        self::assertSame('budget', $ledger->entry($old->id())?->detail?->closes[0]->unavailable);
        self::assertNull($ledger->entry($old->id())?->detail?->income);
        self::assertTrue($ledger->ratesIncomplete);
    }

    public function testPositionsTheReportAlreadyCalculatedAreReused(): void
    {
        $provider = new CountingNbpRateProvider(new FixedNbpRateProvider());
        $calculator = new StockTaxCalculator(new NbpExchange($provider));
        $sell = self::stock('AAA', '2024-06-03', '-10', '1500.00');
        $rows = self::rows([self::stock('AAA', '2024-01-02', '10', '1000.00'), $sell]);
        $settlement = $this->settle($rows);
        $report = $calculator->calculate($settlement->positions)->positions;
        $calls = $provider->calls;

        $ledger = (new TradeLedgerBuilder($calculator))->build($rows, [], $settlement, $report);

        self::assertSame($report[0], $ledger->entry($sell->id())?->detail?->closes[0]->calculated);
        self::assertSame($calls, $provider->calls);
    }

    public function testWithoutASettlementTheRowsAreStillGrouped(): void
    {
        $rows = self::rows([self::stock('AAA', '2024-01-02', '10', '1000.00')]);

        $ledger = $this->builder()->build($rows);

        self::assertFalse($ledger->fifoAvailable);
        self::assertCount(1, $ledger->entries());
        self::assertNull($ledger->entries()[0]->detail);
        self::assertNull($ledger->sections[0]->groups[0]->openQuantity);
    }

    public function testBlankCountriesAreCountedWithTheIdOfTheirCountryGroup(): void
    {
        $rows = self::rows([
            self::stock('AAA', '2024-01-02', '10', '1000.00', country: ''),
            self::stock('AAA', '2024-02-02', '-4', '500.00', country: ''),
            self::stock('BBB', '2024-02-02', '1', '100.00'),
        ]);

        $ledger = $this->builder()->build($rows, [
            0 => ['group_id' => 'g-aaa', 'empty_count' => 2],
            1 => ['group_id' => 'g-aaa', 'empty_count' => 2],
            2 => ['group_id' => 'g-bbb', 'empty_count' => 0],
        ]);

        [$aaa, $bbb] = $ledger->sections[0]->groups;
        self::assertSame(2, $aaa->blankCountries);
        self::assertSame('g-aaa', $aaa->countryGroupId);
        self::assertSame([], $aaa->countries);
        self::assertSame(0, $bbb->blankCountries);
        self::assertSame(['US'], $bbb->countries);
    }

    public function testRowMessagesAreAttachedAndABrokenRowOpensItsEditor(): void
    {
        $buy = self::stock('AAA', '2024-01-02', '10', '1000.00');
        $sell = self::stock('AAA', '2024-02-02', '-4', '500.00');
        $rows = self::rows([$buy, $sell]);
        $rows[] = ['id' => '', 'broker' => 'Ręczne', 'pool' => 'CCC', 'symbol' => 'CCC', 'name' => 'CCC', 'date' => 'jutro'] + $rows[0];

        $ledger = $this->builder()->build($rows, [], null, [], [
            Diagnostic::blocking('trade.invalid', 'Transakcja 1: zła data.', 'transactions', $buy->id()),
            Diagnostic::review('fifo.unmatched_sell', 'Sprzedaż bez zakupu.', 'transactions', $sell->id()),
        ]);

        self::assertTrue($ledger->entry($buy->id())?->editorOpen);
        self::assertSame(['Transakcja 1: zła data.'], array_map(
            static fn (Diagnostic $item): string => $item->message,
            $ledger->entry($buy->id())->messages ?? [],
        ));
        self::assertFalse($ledger->entry($sell->id())?->editorOpen);
        self::assertTrue($ledger->entries()[2]->editorOpen, 'A row the server could not give an id was never saved.');
    }

    private function builder(): TradeLedgerBuilder
    {
        return new TradeLedgerBuilder(new StockTaxCalculator(FixedExchange::create()));
    }

    /** @param list<Trade> $trades */
    private function ledger(array $trades, ?TradeLedgerBuilder $builder = null): TradeLedger
    {
        $rows = self::rows($trades);

        return ($builder ?? $this->builder())->build($rows, [], $this->settle($rows));
    }

    /** @param list<array<string, string>> $rows */
    private function settle(array $rows): SettlementResult
    {
        return (new WorkbenchCalculator(new FifoMatcher()))->settle((new RowFormMapper())->mapTrades($rows)->trades);
    }

    /**
     * @param list<Trade> $trades
     *
     * @return list<array<string, string>>
     */
    private static function rows(array $trades): array
    {
        $mapper = new RowFormMapper();

        return $mapper->mapTrades(array_map($mapper->tradeToForm(...), $trades))->rows;
    }

    /** @return list<string> */
    private static function fingerprints(SettlementResult $settlement): array
    {
        $fingerprints = array_map(static fn (ClosedPosition $position): string => $position->fingerprint(), $settlement->positions);
        sort($fingerprints);

        return $fingerprints;
    }

    private static function stock(
        string $symbol,
        string $date,
        string $quantity,
        string $total,
        string $currency = 'USD',
        string $time = '10:00:00',
        ?string $commission = null,
        string $name = '',
        string $country = 'US',
        ?string $externalId = null,
    ): Trade {
        return new Trade(
            $symbol,
            new DateTimeImmutable($date.' '.$time),
            Decimal::of($quantity),
            Amount::of($total, $currency),
            $externalId ?? 'auto:'.$symbol.$date.$quantity,
            'as.csv',
            new InstrumentDetails('' === $name ? $symbol : $name, $country),
            broker: 'IBKR',
            commission: null === $commission ? null : Amount::of($commission, $currency),
            fifoPool: $symbol.'@'.$currency,
        );
    }

    private static function option(string $quantity, string $total, string $commission, PositionEffect $effect, string $date): Trade
    {
        return new Trade(
            self::PUT,
            new DateTimeImmutable($date.' 10:00:00'),
            Decimal::of($quantity),
            Amount::of($total, 'USD'),
            'auto:'.$date.$quantity,
            'as.csv',
            new InstrumentDetails(self::PUT, 'US', 'XCBO'),
            broker: 'IBKR',
            commission: Amount::of($commission, 'USD'),
            fifoPool: self::PUT.'@USD',
            kind: InstrumentKind::Option,
            effect: $effect,
        );
    }
}


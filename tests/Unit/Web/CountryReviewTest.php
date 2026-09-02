<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Web\CountryReview;
use App\Web\DiagnosticLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountryReview::class)]
final class CountryReviewTest extends TestCase
{
    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function trade(array $overrides = []): array
    {
        return $overrides + [
            'id' => 't1', 'broker' => 'DEGIRO', 'pool' => 'US000ALFA001', 'symbol' => 'US000ALFA001',
            'name' => 'ALFA CORP', 'country' => '', 'exchange' => '',
        ];
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function dividend(array $overrides = []): array
    {
        return $overrides + ['id' => 'd1', 'name' => 'ALFA CORP', 'country' => ''];
    }

    /**
     * @param list<array<string, string>> $trades
     * @param list<array<string, string>> $dividends
     */
    private static function inspect(array $trades, array $dividends = []): \App\Web\CountryReviewResult
    {
        return (new CountryReview())->inspect($trades, $dividends);
    }

    /**
     * @param list<\App\Web\Diagnostic> $diagnostics
     *
     * @return list<\App\Web\Diagnostic>
     */
    private static function withCode(array $diagnostics, string $code): array
    {
        return array_values(array_filter($diagnostics, static fn ($d): bool => $d->code === $code));
    }

    /**
     * Trades and dividends of one paper answer *different* legal questions: a
     * dividend's country is the residence of the payer, which sets the treaty
     * cap, while a trade's is the place of disposal. A Canadian issuer listed in
     * the US legitimately has CA on the dividend and US on the trades, so one
     * group spanning both tabs would offer a button that writes a wrong value
     * into one of them.
     */
    public function testTradesAndDividendsOfOneInstrumentAreSeparateGroups(): void
    {
        $result = self::inspect(
            [self::trade(['id' => 't1']), self::trade(['id' => 't2'])],
            [self::dividend()],
        );

        self::assertCount(2, $result->actions);
        self::assertNotSame(
            $result->tradeGroups[0]['group_id'],
            $result->dividendGroups[0]['group_id'],
        );

        $tradeAction = $result->actions[$result->tradeGroups[0]['group_id']];
        $dividendAction = $result->actions[$result->dividendGroups[0]['group_id']];
        self::assertSame(2, $tradeAction->blankCount);
        self::assertSame(1, $dividendAction->blankCount);
    }

    public function testACanadianIssuerListedInTheUsReportsNoConflict(): void
    {
        $result = self::inspect(
            [self::trade(['id' => 't1', 'symbol' => 'CA000DELTA01', 'pool' => 'CA000DELTA01',
                'name' => 'DELTA MINING', 'country' => 'US', 'exchange' => 'NSY'])],
            [self::dividend(['name' => 'DELTA MINING', 'country' => 'CA'])],
        );

        self::assertSame([], $result->diagnostics);
        self::assertSame([], $result->actions);
    }

    public function testAConflictWithinTheTradesOfOnePaperIsStillReported(): void
    {
        $result = self::inspect([
            self::trade(['id' => 't1', 'country' => 'US']),
            self::trade(['id' => 't2', 'country' => 'DE']),
        ]);

        $conflict = self::withCode($result->diagnostics, 'country.conflict_instrument');
        self::assertCount(1, $conflict);
        self::assertSame(DiagnosticLevel::Review, $conflict[0]->level);
        self::assertSame('transactions', $conflict[0]->targetTab);
    }

    public function testOneBlockingDiagnosticPerScopeNamesItsOwnReason(): void
    {
        $result = self::inspect(
            [self::trade(['id' => 't1']), self::trade(['id' => 't2'])],
            [self::dividend()],
        );

        $missing = self::withCode($result->diagnostics, 'country.missing_instrument');
        self::assertCount(2, $missing);

        $byTab = [];
        foreach ($missing as $item) {
            $byTab[(string) $item->targetTab] = $item;
        }

        self::assertSame(DiagnosticLevel::Blocking, $byTab['transactions']->level);
        self::assertStringContainsString('2 transakcj', $byTab['transactions']->message);
        self::assertStringContainsString('PIT/ZG', $byTab['transactions']->message);
        self::assertSame('t1', $byTab['transactions']->rowId);

        self::assertStringContainsString('1 dywidend', $byTab['dividends']->message);
        self::assertStringContainsString('stawki umownej', $byTab['dividends']->message);
        self::assertStringNotContainsString('PIT/ZG', $byTab['dividends']->message);
    }

    /**
     * CLAUDE.md: trades from different brokers never share a pool, and one
     * broker's ticker says nothing about another's.
     */
    public function testTwoBrokersWithTheSameTickerStayApart(): void
    {
        $result = self::inspect([
            self::trade(['id' => 't1', 'broker' => 'IBKR', 'symbol' => 'CSPX', 'pool' => 'CSPX@USD', 'name' => 'CSPX']),
            self::trade(['id' => 't2', 'broker' => 'DEGIRO', 'symbol' => 'CSPX', 'pool' => 'CSPX', 'name' => 'CSPX']),
        ]);

        self::assertCount(2, $result->actions);
    }

    /**
     * The country is a property of the paper, not of the currency the pool is
     * keyed on, so both IBKR currency pools of one ticker move together.
     */
    public function testTwoCurrencyPoolsOfOneSymbolShareTheGroup(): void
    {
        $result = self::inspect([
            self::trade(['id' => 't1', 'broker' => 'IBKR', 'symbol' => 'CSPX', 'pool' => 'CSPX@USD', 'name' => 'CSPX']),
            self::trade(['id' => 't2', 'broker' => 'IBKR', 'symbol' => 'CSPX', 'pool' => 'CSPX@EUR', 'name' => 'CSPX']),
        ]);

        self::assertCount(1, $result->actions);
        self::assertSame(2, array_values($result->actions)[0]->blankCount);
    }

    /**
     * Dividends group among themselves, by name: two payments of one paper are
     * one item and one click, whatever the trades look like.
     */
    public function testDividendsOfOneNameShareOneGroup(): void
    {
        $result = self::inspect([], [
            self::dividend(['id' => 'd1']),
            self::dividend(['id' => 'd2', 'name' => "alfa\u{00A0}corp"]),
        ]);

        self::assertCount(1, $result->actions);
        self::assertSame(2, array_values($result->actions)[0]->blankCount);
        self::assertSame(
            $result->dividendGroups[0]['group_id'],
            $result->dividendGroups[1]['group_id'],
        );
    }

    public function testAGroupWithoutBlanksProducesNoBlockingDiagnostic(): void
    {
        $result = self::inspect(
            [self::trade(['id' => 't1', 'country' => 'US'])],
            [self::dividend(['country' => 'US'])],
        );

        self::assertSame([], self::withCode($result->diagnostics, 'country.missing_instrument'));
        self::assertSame([], $result->actions);
    }

    /**
     * The listing exchange and the ISIN registration country are two accepted
     * readings of one question, so their disagreement is a setting, not a
     * finding - it must never reach the attention panel.
     */
    public function testTheExchangeAndIsinDisagreementIsNotReported(): void
    {
        $result = self::inspect([
            self::trade(['id' => 't1', 'symbol' => 'IE000BETA002', 'pool' => 'IE000BETA002',
                'name' => 'BETA ETF', 'country' => 'NL', 'exchange' => 'EAM']),
        ]);

        self::assertSame([], $result->diagnostics);
    }

    public function testTheGroupIdDoesNotMoveWhenTheFirstBlankRowIsFilled(): void
    {
        $before = self::inspect([self::trade(['id' => 't1']), self::trade(['id' => 't2'])]);
        $after = self::inspect([
            self::trade(['id' => 't1', 'country' => 'US']),
            self::trade(['id' => 't2']),
        ]);

        self::assertSame($before->tradeGroups[0]['group_id'], $after->tradeGroups[0]['group_id']);
    }

    public function testGroupMembershipIsExposedForTheBulkWriter(): void
    {
        $groups = (new CountryReview())->groups(
            [self::trade(['id' => 't1']), self::trade(['id' => 't2', 'symbol' => 'IE000BETA002', 'pool' => 'IE000BETA002', 'name' => 'BETA ETF'])],
            [self::dividend()],
        );

        // Two trade groups plus one dividend group - never merged.
        self::assertCount(3, $groups);

        $byScopeAndLabel = [];
        foreach ($groups as $group) {
            $byScopeAndLabel[$group->scope->value.'|'.$group->label] = $group;
        }

        self::assertSame([0], $byScopeAndLabel['trades|ALFA CORP']->indexes);
        self::assertSame([1], $byScopeAndLabel['trades|BETA ETF']->indexes);
        self::assertSame([0], $byScopeAndLabel['dividends|ALFA CORP']->indexes);
    }
}

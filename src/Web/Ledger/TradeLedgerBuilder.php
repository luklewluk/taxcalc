<?php

declare(strict_types=1);

namespace App\Web\Ledger;

use App\Exception\ExchangeRateUnavailableException;
use App\Exception\InvalidDateException;
use App\Fifo\FifoMatch;
use App\Fifo\FifoViolationKind;
use App\Fifo\PositionDirection;
use App\Import\Parser\DateParser;
use App\Model\ClosedPosition;
use App\Money\Amount;
use App\Money\Decimal;
use App\Tax\Result\CalculatedPosition;
use App\Tax\StockTaxCalculator;
use App\Tax\TaxRates;
use App\Web\Diagnostic;
use App\Web\DiagnosticLevel;
use App\Web\SettlementResult;
use Closure;
use DateTimeImmutable;

/**
 * Builds the Transakcje tab from the posted rows and the FIFO run over them.
 *
 * A group is exactly one FIFO queue (`broker|pool`), and its rows are sorted
 * the way FIFO sorts them - stably, by date and time - so posting the rows back
 * in this order cannot change a single match: ties keep their relative order,
 * no queue is split, and match ordinals are counted per queue.
 *
 * The details cover every year, not only the settled one, so they need NBP
 * rates the report never asked for. Positions the report already converted
 * are reused; the rest are converted latest close first, within a time budget
 * and with a per-currency breaker, because each missing rate can cost a
 * network round trip. Whatever is left says so, and the next render - with a
 * warmer rate cache - fills it in.
 */
final readonly class TradeLedgerBuilder
{
    public const int MAX_RATE_FAILURES_PER_CURRENCY = 3;

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param (Closure(): float)|null $clock seconds, for the budget; microtime by default
     */
    public function __construct(
        private StockTaxCalculator $calculator,
        private TaxRates $taxRates = new TaxRates(),
        private float $rateBudgetSeconds = 10.0,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * @param list<array<string, string>>                           $rows          the posted trade rows, by index
     * @param array<int, array{group_id: string, empty_count: int}> $countryGroups per row index, see {@see \App\Web\CountryReview}
     * @param list<CalculatedPosition>                              $calculated    positions the report already converted
     * @param list<Diagnostic>                                      $diagnostics
     */
    public function build(
        array $rows,
        array $countryGroups = [],
        ?SettlementResult $settlement = null,
        array $calculated = [],
        array $diagnostics = [],
    ): TradeLedger {
        $rowsById = [];
        foreach ($rows as $row) {
            if ('' !== ($row['id'] ?? '')) {
                $rowsById[$row['id']] = $row;
            }
        }

        [$closes, $closedBy, $ratesIncomplete] = null === $settlement
            ? [[], [], false]
            : $this->matchDetails($settlement, $rowsById, $calculated);

        $messages = [];
        foreach ($diagnostics as $diagnostic) {
            if (null !== $diagnostic->rowId && '' !== $diagnostic->rowId) {
                $messages[$diagnostic->rowId][] = $diagnostic;
            }
        }

        /** @var array<string, list<array{int, array<string, string>, ?DateTimeImmutable}>> $queues */
        $queues = [];
        foreach ($rows as $index => $row) {
            $queues[self::queueKey($row)][] = [$index, $row, self::timestamp($row)];
        }

        $sections = [LedgerSection::STOCKS => [], LedgerSection::OPTIONS => []];
        foreach ($queues as $key => $members) {
            // Stable, and on the same key FIFO sorts on. A row whose date does
            // not parse never reaches FIFO; it goes last, in posted order.
            usort($members, static function (array $a, array $b): int {
                if (null === $a[2] || null === $b[2]) {
                    return (null === $a[2]) <=> (null === $b[2]);
                }

                return $a[2] <=> $b[2];
            });

            $entries = [];
            foreach ($members as [$index, $row]) {
                $id = $row['id'] ?? '';
                $rowMessages = $messages[$id] ?? [];
                $entries[] = new LedgerEntry(
                    $index,
                    $id,
                    $row,
                    self::isOptionRow($row),
                    null === $settlement || '' === $id ? null : $this->tradeDetail($id, $settlement, $closes, $closedBy),
                    '' === $id ? [] : $rowMessages,
                    '' === $id || self::rejectedRow($rowMessages),
                );
            }

            $group = $this->group($key, $entries, $members, $countryGroups, $settlement);
            $sections[$group->isOption ? LedgerSection::OPTIONS : LedgerSection::STOCKS][] = $group;
        }

        $result = [];
        foreach ([LedgerSection::STOCKS => 'Akcje i ETF-y', LedgerSection::OPTIONS => 'Opcje'] as $key => $title) {
            if ([] === $sections[$key]) {
                continue;
            }

            $groups = $sections[$key];
            usort($groups, static fn (InstrumentGroup $a, InstrumentGroup $b): int => [mb_strtolower($a->title), $a->key] <=> [mb_strtolower($b->title), $b->key]);
            $result[] = new LedgerSection($key, $title, $groups);
        }

        return new TradeLedger($result, null !== $settlement, $ratesIncomplete);
    }

    /**
     * @param array<string, array<string, string>> $rowsById
     * @param list<CalculatedPosition>             $calculated
     *
     * @return array{array<string, list<MatchDetail>>, array<string, list<MatchDetail>>, bool}
     */
    private function matchDetails(SettlementResult $settlement, array $rowsById, array $calculated): array
    {
        [$converted, $unavailable] = $this->convert($settlement->matchPositions, $calculated);

        $closes = [];
        $closedBy = [];
        foreach ($settlement->matches as $index => $match) {
            $position = $settlement->matchPositions[$index] ?? null;
            $result = $converted[$index] ?? null;
            $reason = $unavailable[$index] ?? (null === $position ? MatchDetail::POSITION : null);
            $tax = null === $result ? null : $this->informationalTax($result->income);
            [$closing, $opening] = self::sides($match);

            $closes[$closing][] = new MatchDetail($match, $position, $opening, $rowsById[$opening] ?? null, $result, $reason, $tax);
            $closedBy[$opening][] = new MatchDetail($match, $position, $closing, $rowsById[$closing] ?? null, $result, $reason, $tax);
        }

        $incomplete = [] !== array_filter(
            $unavailable,
            static fn (string $reason): bool => MatchDetail::POSITION !== $reason,
        );

        return [$closes, $closedBy, $incomplete];
    }

    /**
     * @param array<int, ClosedPosition> $positions by match index
     * @param list<CalculatedPosition>   $calculated
     *
     * @return array{array<int, CalculatedPosition>, array<int, string>}
     */
    private function convert(array $positions, array $calculated): array
    {
        $known = [];
        foreach ($calculated as $item) {
            $known[spl_object_id($item->position)] = $item;
        }

        $converted = [];
        $pending = [];
        foreach ($positions as $index => $position) {
            if (isset($known[spl_object_id($position)])) {
                $converted[$index] = $known[spl_object_id($position)];
            } else {
                $pending[$index] = $position;
            }
        }

        // The latest closes first: they are the ones a user is settling now.
        uasort($pending, static fn (ClosedPosition $a, ClosedPosition $b): int => $b->closeDate() <=> $a->closeDate());

        $unavailable = [];
        $failures = [];
        $started = ($this->clock)();
        foreach ($pending as $index => $position) {
            if (($failures[$position->currency] ?? 0) >= self::MAX_RATE_FAILURES_PER_CURRENCY) {
                $unavailable[$index] = MatchDetail::RATE;

                continue;
            }

            if (($this->clock)() - $started > $this->rateBudgetSeconds) {
                $unavailable[$index] = MatchDetail::BUDGET;

                continue;
            }

            try {
                $result = $this->calculator->calculate([$position])->positions[0] ?? null;
            } catch (ExchangeRateUnavailableException) {
                $failures[$position->currency] = ($failures[$position->currency] ?? 0) + 1;
                $unavailable[$index] = MatchDetail::RATE;

                continue;
            }

            if (null !== $result) {
                $converted[$index] = $result;
            }
        }

        return [$converted, $unavailable];
    }

    /**
     * @param array<string, list<MatchDetail>> $closes
     * @param array<string, list<MatchDetail>> $closedBy
     */
    private function tradeDetail(string $id, SettlementResult $settlement, array $closes, array $closedBy): TradeDetail
    {
        $open = null;
        $direction = null;
        foreach ($settlement->openPositions as $position) {
            if ($position->trade->id() === $id) {
                $open = null === $open ? $position->quantity : $open->plus($position->quantity);
                $direction = $position->direction;
            }
        }

        $unmatched = null;
        $notices = [];
        foreach ($settlement->unmatchedSells as $sell) {
            if ($sell->tradeId === $id) {
                $unmatched = null === $unmatched ? $sell->quantity : $unmatched->plus($sell->quantity);
                $notices[] = $sell->describe();
            }
        }
        foreach ($settlement->violations as $violation) {
            if ($violation->tradeId !== $id) {
                continue;
            }
            if (FifoViolationKind::UnmatchedClose === $violation->kind) {
                $unmatched = null === $unmatched ? $violation->quantity : $unmatched->plus($violation->quantity);
            }
            $notices[] = $violation->describe();
        }

        $own = $closes[$id] ?? [];
        $income = null;
        $taxYear = null;
        if ([] !== $own) {
            $first = $own[0];
            $taxYear = $first->position?->taxYear() ?? (int) $first->match->closeDate()->format('Y');
            $income = Amount::zero('PLN');
            foreach ($own as $detail) {
                if (null === $detail->calculated) {
                    $income = null;

                    break;
                }
                $income = $income->plus($detail->calculated->income);
            }
        }

        return new TradeDetail(
            $own,
            $closedBy[$id] ?? [],
            $open,
            $direction,
            $unmatched,
            $notices,
            $taxYear,
            $income,
            null === $income ? null : $this->informationalTax($income),
        );
    }

    /**
     * @param list<LedgerEntry>                                            $entries
     * @param list<array{int, array<string, string>, ?DateTimeImmutable}>  $members
     * @param array<int, array{group_id: string, empty_count: int}>        $countryGroups
     */
    private function group(string $key, array $entries, array $members, array $countryGroups, ?SettlementResult $settlement): InstrumentGroup
    {
        // Named after its latest trade - a broker renames a paper, the queue
        // stays. Rows with an unparsable date are a last resort.
        $last = [];
        foreach (array_reverse($members) as [, $row, $timestamp]) {
            $last = [] === $last ? $row : $last;
            if (null !== $timestamp) {
                $last = $row;

                break;
            }
        }

        $kinds = array_unique(array_map(static fn (LedgerEntry $entry): bool => $entry->isOption, $entries));
        $countries = [];
        $blank = 0;
        $countryGroupId = '';
        foreach ($members as [$index, $row]) {
            $country = $row['country'] ?? '';
            if ('' === $country) {
                ++$blank;
                $countryGroupId = '' === $countryGroupId ? ($countryGroups[$index]['group_id'] ?? '') : $countryGroupId;
            } else {
                $countries[$country] = true;
            }
        }
        $countries = array_keys($countries);
        sort($countries);

        $ids = array_fill_keys(array_filter(array_map(static fn (LedgerEntry $entry): string => $entry->id, $entries)), true);
        $open = null;
        $direction = null;
        if (null !== $settlement) {
            $open = Decimal::zero();
            foreach ($settlement->openPositions as $position) {
                if (isset($ids[$position->trade->id()])) {
                    $open = $open->plus($position->quantity);
                    $direction = $position->direction;
                }
            }
            foreach ($settlement->matches as $match) {
                if (null !== $direction) {
                    break;
                }
                if (isset($ids[$match->buyTradeId]) || isset($ids[$match->sellTradeId])) {
                    $direction = $match->direction;
                }
            }
        }

        return new InstrumentGroup(
            $key,
            $last['broker'] ?? '',
            self::pool($last),
            $last['symbol'] ?? '',
            ($last['name'] ?? '') ?: ($last['symbol'] ?? ''),
            [true] === array_values($kinds),
            count($kinds) > 1,
            $countries,
            $blank,
            $countryGroupId,
            $open,
            $direction,
            $entries,
        );
    }

    private function informationalTax(Amount $income): Amount
    {
        return $income->isPositive()
            ? $income->percentage($this->taxRates->polishRatePercent())
            : Amount::zero($income->currency());
    }

    /** @return array{string, string} the closing trade's id, then the opening one's */
    private static function sides(FifoMatch $match): array
    {
        return PositionDirection::Short === $match->direction
            ? [$match->buyTradeId, $match->sellTradeId]
            : [$match->sellTradeId, $match->buyTradeId];
    }

    /** @param list<Diagnostic> $messages */
    private static function rejectedRow(array $messages): bool
    {
        foreach ($messages as $message) {
            if (DiagnosticLevel::Blocking === $message->level && str_starts_with($message->code, 'trade.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The key {@see \App\Fifo\FifoMatcher} queues on, from the same values the
     * mapper turns into a trade.
     *
     * @param array<string, string> $row
     */
    private static function queueKey(array $row): string
    {
        return ($row['broker'] ?? '').'|'.self::pool($row);
    }

    /** @param array<string, string> $row */
    private static function pool(array $row): string
    {
        return ($row['pool'] ?? '') ?: ($row['symbol'] ?? '');
    }

    /** @param array<string, string> $row */
    private static function isOptionRow(array $row): bool
    {
        return 'OPT' === strtoupper($row['asset'] ?? '');
    }

    /** @param array<string, string> $row */
    private static function timestamp(array $row): ?DateTimeImmutable
    {
        try {
            return DateParser::parseWithTime($row['date'] ?? '', $row['time'] ?? '');
        } catch (InvalidDateException) {
            return null;
        }
    }
}

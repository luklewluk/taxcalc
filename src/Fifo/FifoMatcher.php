<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Money\Amount;
use App\Money\Decimal;

/**
 * Matches sells against buys using FIFO (first in, first out), the method
 * required for Polish PIT-38 settlements.
 *
 * All quantities and amounts are decimals, so fractional shares and sub-cent
 * prices are handled without float drift. When a buy lot is only partially
 * consumed, its cost is prorated; the final slice of a lot always receives the
 * exact remaining balance so prorated parts sum back to the lot total.
 *
 * Buy lots are never filtered by year: an opening trade from an earlier tax
 * year stays available to match a sale in the year being settled.
 *
 * A stock sale the user tied to named lots ({@see LotAssignments}) consumes
 * exactly those instead - specific identification, which art. 30b ust. 7
 * allows when the units sold can be identified. Every other sale stays FIFO,
 * and an assignment that cannot be honoured is a violation, never a silent
 * fallback to FIFO.
 */
final class FifoMatcher
{
    /**
     * @param list<Trade> $trades
     */
    public function match(array $trades, LotAssignments $assignments = new LotAssignments()): FifoResult
    {
        $bySymbol = self::queues($trades);
        $index = self::index($trades);

        $matches = [];
        $unmatched = [];
        $violations = [];
        $open = [];
        /** @var array<string, true> $stockSales sales of a stock-only queue, which read their assignment */
        $stockSales = [];

        foreach ($bySymbol as $symbolTrades) {
            $kinds = array_unique(array_map(static fn (Trade $trade): string => $trade->kind->value, $symbolTrades));
            if (count($kinds) > 1) {
                $first = $symbolTrades[0];
                $violations[] = new FifoViolation(
                    FifoViolationKind::MixedInstrumentKinds,
                    $first->symbol,
                    $first->date,
                    $first->quantity->abs(),
                    $first->id(),
                );

                continue;
            }

            if (InstrumentKind::Option !== $symbolTrades[0]->kind) {
                foreach ($symbolTrades as $trade) {
                    if ($trade->isSell()) {
                        $stockSales[$trade->id()] = true;
                    }
                }
            }

            $result = InstrumentKind::Option === $symbolTrades[0]->kind
                ? $this->matchOptionPool($symbolTrades)
                : $this->matchSymbol($symbolTrades, $assignments, $index);
            $matches = [...$matches, ...$result->matches];
            $unmatched = [...$unmatched, ...$result->unmatchedSells];
            $violations = [...$violations, ...$result->violations];
            $open = [...$open, ...$result->openPositions];
        }

        // Lots named for a trade that is no stock sale: a buy, an option, a
        // zero-quantity row. A sale of a queue already blocked as mixed, or an
        // id that is no trade at all, changes no figure and is left alone.
        foreach ($assignments->saleIds() as $saleId) {
            $trade = $index[$saleId] ?? null;
            if (null === $trade || isset($stockSales[$saleId])) {
                continue;
            }
            if ($trade->isOption() || !$trade->isSell()) {
                $violations[] = self::violation(FifoViolationKind::AssignmentNotASale, $trade, $trade->quantity->abs());
            }
        }

        return new FifoResult($matches, $unmatched, $violations, $open);
    }

    /**
     * The lots of a stock sale's queue still open just before that sale - what
     * the user may name for it. Null when the id is no stock sale.
     *
     * @param list<Trade> $trades
     *
     * @return list<OpenPosition>|null
     */
    public function openLotsBefore(array $trades, LotAssignments $assignments, string $saleId): ?array
    {
        $index = self::index($trades);
        $sale = $index[$saleId] ?? null;
        if (null === $sale || $sale->isOption() || !$sale->isSell()) {
            return null;
        }

        foreach (self::queues($trades) as $queue) {
            if (!in_array($sale, $queue, true)) {
                continue;
            }
            foreach ($queue as $trade) {
                if ($trade->isOption()) {
                    return null;
                }
            }

            return $this->matchSymbol($queue, $assignments, $index, $saleId)->openPositions;
        }

        return null;
    }

    /**
     * @param list<Trade> $trades
     *
     * @return array<string, list<Trade>>
     */
    private static function queues(array $trades): array
    {
        $bySymbol = [];
        foreach ($trades as $trade) {
            $pool = '' === $trade->fifoPool ? $trade->symbol : $trade->fifoPool;
            $bySymbol[$trade->broker.'|'.$pool][] = $trade;
        }

        return $bySymbol;
    }

    /**
     * @param list<Trade> $trades
     *
     * @return array<string, Trade>
     */
    private static function index(array $trades): array
    {
        $index = [];
        foreach ($trades as $trade) {
            $index[$trade->id()] ??= $trade;
        }

        return $index;
    }

    /**
     * One option series. Either side may open a position, and the trade's
     * declared {@see PositionEffect} - never the order of rows - says whether
     * it opens or closes: inferring it would turn a buy-to-close whose writing
     * sale was not uploaded into a long lot and lose the premium silently.
     *
     * Kept apart from {@see matchSymbol()} so stock matching stays exactly what
     * it was.
     *
     * @param list<Trade> $trades all belonging to a single option series
     */
    private function matchOptionPool(array $trades): FifoResult
    {
        usort($trades, static fn (Trade $a, Trade $b) => $a->date <=> $b->date);

        /** @var array{long: list<OpenLot>, short: list<OpenLot>} $lots */
        $lots = ['long' => [], 'short' => []];
        $matches = [];
        $violations = [];
        $sequence = 0;

        foreach ($trades as $trade) {
            if ($trade->quantity->isZero()) {
                continue;
            }

            $own = $trade->isBuy() ? 'long' : 'short';
            $opposite = $trade->isBuy() ? 'short' : 'long';

            if (null === $trade->effect) {
                $violations[] = self::violation(FifoViolationKind::MissingEffect, $trade, $trade->quantity->abs());

                continue;
            }

            if (PositionEffect::Open === $trade->effect) {
                if ([] !== self::openLots($lots[$opposite])) {
                    $violations[] = self::violation(FifoViolationKind::OpenAgainstOpposite, $trade, $trade->quantity->abs());

                    continue;
                }

                $lots[$own][] = new OpenLot($trade);

                continue;
            }

            // A close - or the closing half of `C;O` - with nothing open on the
            // other side means its opening is in a statement that was not
            // uploaded. Opening the whole quantity instead would drop that
            // closing sale or buy from the return without a trace.
            if ([] === self::openLots($lots[$opposite])) {
                $violations[] = self::violation(FifoViolationKind::UnmatchedClose, $trade, $trade->quantity->abs());

                continue;
            }

            // A buy closes written options, a sell closes bought ones.
            $direction = $trade->isBuy() ? PositionDirection::Short : PositionDirection::Long;
            [$closed, $left] = $this->closeAgainst($lots[$opposite], $trade, $direction, $sequence);
            $matches = [...$matches, ...$closed];

            [$quantityLeft, $amountLeft, $commissionLeft, $autoFxLeft] = $left;
            if (!$quantityLeft->isPositive()) {
                continue;
            }

            if (PositionEffect::Close === $trade->effect) {
                $violations[] = self::violation(FifoViolationKind::UnmatchedClose, $trade, $quantityLeft);

                continue;
            }

            $lots[$own][] = OpenLot::remainderOf($trade, $quantityLeft, $amountLeft, $commissionLeft, $autoFxLeft);
        }

        return new FifoResult($matches, [], $violations, [
            ...self::openPositions($lots['long'], PositionDirection::Long),
            ...self::openPositions($lots['short'], PositionDirection::Short),
        ]);
    }

    /**
     * Closes as much of `$closer` as the open lots allow, oldest lot first.
     *
     * For a long position the lot is the buy and the closer the sell; for a
     * written one the lot is the sell (the premium received) and the closer
     * the buy. Slicing is the same as for stocks: the closer is prorated, the
     * lot gives up exact remainders.
     *
     * @param list<OpenLot> $lots
     *
     * @return array{list<FifoMatch>, array{Decimal, Amount, ?Amount, ?Amount}} the matches and what is left
     *                                                                           of the closer
     */
    private function closeAgainst(array $lots, Trade $closer, PositionDirection $direction, int &$sequence): array
    {
        $qtyLeft = $closer->quantity->abs();
        $qtyTotal = $qtyLeft;
        $amountLeft = $closer->grossAmount->abs();
        $commissionLeft = $closer->commission?->abs();
        $autoFxLeft = $closer->autoFx?->abs();
        $matches = [];

        foreach ($lots as $lot) {
            if (!$qtyLeft->isPositive()) {
                break;
            }

            if (!$lot->remainingQuantity->isPositive()) {
                continue;
            }

            $matchedQty = $lot->remainingQuantity->min($qtyLeft);
            $lotQtyBefore = $lot->remainingQuantity;
            $lotAmount = $lot->take($matchedQty);
            [$lotCommission, $lotAutoFx] = $lot->takeFees($matchedQty, $lotQtyBefore);

            $closerAmount = $this->slice($amountLeft, $matchedQty, $qtyLeft, $qtyTotal, $closer->grossAmount->abs());
            $closerCommission = $this->sliceOptional($closer->commission, $commissionLeft, $matchedQty, $qtyLeft, $qtyTotal);
            $closerAutoFx = $this->sliceOptional($closer->autoFx, $autoFxLeft, $matchedQty, $qtyLeft, $qtyTotal);

            $long = PositionDirection::Long === $direction;
            [$buy, $sell] = $long ? [$lot->trade, $closer] : [$closer, $lot->trade];

            $matches[] = new FifoMatch(
                $closer->symbol,
                $buy->date,
                $sell->date,
                $matchedQty,
                $long ? $lotAmount : $closerAmount,
                $long ? $closerAmount : $lotAmount,
                $buy->externalId,
                $sell->externalId,
                $buy->source,
                $sell->source,
                ++$sequence,
                $buy->instrument,
                $sell->instrument,
                $long ? $lotCommission : $closerCommission,
                $long ? $closerCommission : $lotCommission,
                $long ? $lotAutoFx : $closerAutoFx,
                $long ? $closerAutoFx : $lotAutoFx,
                $closer->broker,
                $buy->id(),
                $sell->id(),
                $buy->unitPrice,
                $sell->unitPrice,
                InstrumentKind::Option,
                $direction,
            );

            $qtyLeft = $qtyLeft->minus($matchedQty);
            $amountLeft = $amountLeft->minus($closerAmount);
            if (null !== $commissionLeft && null !== $closerCommission) {
                $commissionLeft = $commissionLeft->minus($closerCommission);
            }
            if (null !== $autoFxLeft && null !== $closerAutoFx) {
                $autoFxLeft = $autoFxLeft->minus($closerAutoFx);
            }
        }

        return [$matches, [$qtyLeft, $amountLeft, $commissionLeft, $autoFxLeft]];
    }

    /**
     * @param list<OpenLot> $lots
     *
     * @return list<OpenLot>
     */
    private static function openLots(array $lots): array
    {
        return array_values(array_filter($lots, static fn (OpenLot $lot): bool => $lot->remainingQuantity->isPositive()));
    }

    /**
     * @param list<OpenLot> $lots
     *
     * @return list<OpenPosition>
     */
    private static function openPositions(array $lots, PositionDirection $direction): array
    {
        return array_map(
            static fn (OpenLot $lot): OpenPosition => new OpenPosition($lot->trade, $lot->remainingQuantity, $direction),
            self::openLots($lots),
        );
    }

    private static function violation(FifoViolationKind $kind, Trade $trade, Decimal $quantity): FifoViolation
    {
        return new FifoViolation($kind, $trade->symbol, $trade->date, $quantity, $trade->id());
    }

    /**
     * @param list<Trade>          $trades all belonging to a single symbol
     * @param array<string, Trade> $index  every trade of the run, for naming a lot of another queue
     * @param string|null          $stopBefore a sale id: return the lots open just before it
     */
    private function matchSymbol(
        array $trades,
        LotAssignments $assignments = new LotAssignments(),
        array $index = [],
        ?string $stopBefore = null,
    ): FifoResult {
        // Stable sort by date: trades on the same day keep their file order,
        // which is the only ordering information a broker export gives us.
        usort($trades, static fn (Trade $a, Trade $b) => $a->date <=> $b->date);

        /** @var list<OpenLot> $openLots */
        $openLots = [];
        $matches = [];
        $unmatched = [];
        $violations = [];

        /** @var array<string, true> $buys buy ids of this queue */
        $buys = [];
        foreach ($trades as $trade) {
            if ($trade->isBuy()) {
                $buys[$trade->id()] = true;
            }
        }
        /** @var array<string, OpenLot> $lotsById the buys seen so far */
        $lotsById = [];

        // Counted per symbol, not per run: the ordinal only has to separate two
        // otherwise identical matches of *this* queue. Numbering across symbols
        // would make one instrument's lineage - and therefore the fingerprint
        // that decides duplicates - move whenever an unrelated instrument was
        // added to the upload.
        $sequence = 0;

        foreach ($trades as $trade) {
            if (null !== $stopBefore && $trade->id() === $stopBefore) {
                break;
            }

            if ($trade->isBuy()) {
                $lot = new OpenLot($trade);
                $openLots[] = $lot;
                $lotsById[$trade->id()] ??= $lot;

                continue;
            }

            if (!$trade->isSell()) {
                // Zero-quantity rows (corporate action placeholders) carry no
                // cost basis information and are ignored.
                continue;
            }

            $sale = new SaleProgress($trade);
            $allocations = $assignments->for($trade->id());

            if (null !== $allocations) {
                $problems = self::assignmentProblems($trade, $allocations, $buys, $lotsById, $index);
                if ([] !== $problems) {
                    // Nothing consumed, and no "uncovered sale" either - the
                    // assignment is the one thing to fix.
                    $violations = [...$violations, ...$problems];

                    continue;
                }

                $wanted = [];
                foreach ($allocations as $allocation) {
                    $wanted[$allocation->buyTradeId] = $allocation->quantity;
                }
                // Queue order, whatever order the lots were named in.
                foreach ($openLots as $lot) {
                    $quantity = $wanted[$lot->trade->id()] ?? null;
                    if (null !== $quantity) {
                        $matches[] = $this->consume($lot, $quantity, $sale, ++$sequence, LotMethod::Specific);
                    }
                }

                continue;
            }

            foreach ($openLots as $lot) {
                if (!$sale->quantityLeft->isPositive()) {
                    break;
                }

                if (!$lot->remainingQuantity->isPositive()) {
                    continue;
                }

                $matches[] = $this->consume($lot, $lot->remainingQuantity->min($sale->quantityLeft), $sale, ++$sequence, LotMethod::Fifo);
            }

            if ($sale->quantityLeft->isPositive()) {
                $unmatched[] = new UnmatchedSell($trade->symbol, $trade->date, $sale->quantityLeft, $trade->id());
            }
        }

        return new FifoResult($matches, $unmatched, $violations, self::openPositions($openLots, PositionDirection::Long));
    }

    /**
     * Why a sale's named lots cannot be honoured - all of it, before anything
     * is consumed.
     *
     * @param list<LotAllocation>     $allocations
     * @param array<string, true>     $buys     buy ids of the sale's queue
     * @param array<string, OpenLot>  $lotsById buys of the queue seen before the sale
     * @param array<string, Trade>    $index
     *
     * @return list<FifoViolation>
     */
    private static function assignmentProblems(Trade $sale, array $allocations, array $buys, array $lotsById, array $index): array
    {
        $problems = [];
        $sold = $sale->quantity->abs();

        $named = Decimal::zero();
        foreach ($allocations as $allocation) {
            $named = $named->plus($allocation->quantity);
        }
        if (0 !== $named->compareTo($sold)) {
            $problems[] = new FifoViolation(
                FifoViolationKind::LotQuantityMismatch,
                $sale->symbol,
                $sale->date,
                $sold,
                $sale->id(),
                requested: $named,
            );
        }

        foreach ($allocations as $allocation) {
            $id = $allocation->buyTradeId;
            $lot = $lotsById[$id] ?? null;

            if (null === $lot) {
                $other = $index[$id] ?? null;
                $kind = match (true) {
                    isset($buys[$id]) => FifoViolationKind::LotNotYetOpen,
                    null !== $other => FifoViolationKind::LotNotEligible,
                    default => FifoViolationKind::LotMissing,
                };
                $problems[] = new FifoViolation(
                    $kind,
                    $sale->symbol,
                    $sale->date,
                    $sold,
                    $sale->id(),
                    lotDate: $other?->date,
                    requested: $allocation->quantity,
                    lotTradeId: $id,
                );

                continue;
            }

            if ($lot->remainingQuantity->compareTo($allocation->quantity) < 0) {
                $problems[] = new FifoViolation(
                    FifoViolationKind::LotInsufficient,
                    $sale->symbol,
                    $sale->date,
                    $sold,
                    $sale->id(),
                    lotDate: $lot->trade->date,
                    requested: $allocation->quantity,
                    available: $lot->remainingQuantity,
                    lotTradeId: $id,
                );
            }
        }

        return $problems;
    }

    /**
     * Takes `$quantity` from one lot for the sale - the one place FIFO and
     * named lots share, so both prorate the same way: the last slice of a lot
     * or a sale takes the exact remainder, fee slices are never negative.
     */
    private function consume(OpenLot $lot, Decimal $quantity, SaleProgress $sale, int $sequence, LotMethod $method): FifoMatch
    {
        $trade = $sale->trade;
        $lotQtyBefore = $lot->remainingQuantity;
        $buyCost = $lot->take($quantity);
        [$buyCommission, $buyAutoFx] = $lot->takeFees($quantity, $lotQtyBefore);
        $sellProceeds = $this->slice(
            $sale->amountLeft,
            $quantity,
            $sale->quantityLeft,
            $sale->quantityTotal,
            $trade->grossAmount->abs(),
        );
        $sellCommission = $this->sliceOptional(
            $trade->commission,
            $sale->commissionLeft,
            $quantity,
            $sale->quantityLeft,
            $sale->quantityTotal,
        );
        $sellAutoFx = $this->sliceOptional(
            $trade->autoFx,
            $sale->autoFxLeft,
            $quantity,
            $sale->quantityLeft,
            $sale->quantityTotal,
        );

        $sale->quantityLeft = $sale->quantityLeft->minus($quantity);
        $sale->amountLeft = $sale->amountLeft->minus($sellProceeds);
        if (null !== $sale->commissionLeft && null !== $sellCommission) {
            $sale->commissionLeft = $sale->commissionLeft->minus($sellCommission);
        }
        if (null !== $sale->autoFxLeft && null !== $sellAutoFx) {
            $sale->autoFxLeft = $sale->autoFxLeft->minus($sellAutoFx);
        }

        return new FifoMatch(
            $trade->symbol,
            $lot->trade->date,
            $trade->date,
            $quantity,
            $buyCost,
            $sellProceeds,
            $lot->trade->externalId,
            $trade->externalId,
            $lot->trade->source,
            $trade->source,
            $sequence,
            $lot->trade->instrument,
            $trade->instrument,
            $buyCommission,
            $sellCommission,
            $buyAutoFx,
            $sellAutoFx,
            $trade->broker,
            $lot->trade->id(),
            $trade->id(),
            $lot->trade->unitPrice,
            $trade->unitPrice,
            lotMethod: $method,
        );
    }

    /**
     * Proportional slice of the sell proceeds, with the last slice absorbing
     * the rounding remainder so nothing is lost.
     */
    private function slice(
        Amount $remainingAmount,
        Decimal $matchedQty,
        Decimal $remainingQty,
        Decimal $totalQty,
        Amount $totalAmount,
    ): Amount {
        if (0 === $matchedQty->compareTo($remainingQty)) {
            return $remainingAmount;
        }

        return $totalAmount->proratedBy($matchedQty, $totalQty);
    }

    private function sliceOptional(
        ?Amount $total,
        ?Amount $remaining,
        Decimal $matchedQty,
        Decimal $remainingQty,
        Decimal $totalQty,
    ): ?Amount {
        if (null === $total || null === $remaining) {
            return null;
        }

        if (0 === $matchedQty->compareTo($remainingQty)) {
            return $remaining;
        }

        return $total->abs()->proratedBy($matchedQty, $totalQty);
    }
}

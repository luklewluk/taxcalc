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
 */
final class FifoMatcher
{
    /**
     * @param list<Trade> $trades
     */
    public function match(array $trades): FifoResult
    {
        /** @var array<string, list<Trade>> $bySymbol */
        $bySymbol = [];
        foreach ($trades as $trade) {
            $pool = '' === $trade->fifoPool ? $trade->symbol : $trade->fifoPool;
            $bySymbol[$trade->broker.'|'.$pool][] = $trade;
        }

        $matches = [];
        $unmatched = [];
        $violations = [];

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

            $result = InstrumentKind::Option === $symbolTrades[0]->kind
                ? $this->matchOptionPool($symbolTrades)
                : $this->matchSymbol($symbolTrades);
            $matches = [...$matches, ...$result->matches];
            $unmatched = [...$unmatched, ...$result->unmatchedSells];
            $violations = [...$violations, ...$result->violations];
        }

        return new FifoResult($matches, $unmatched, $violations);
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

        return new FifoResult($matches, [], $violations);
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

    private static function violation(FifoViolationKind $kind, Trade $trade, Decimal $quantity): FifoViolation
    {
        return new FifoViolation($kind, $trade->symbol, $trade->date, $quantity, $trade->id());
    }

    /**
     * @param list<Trade> $trades all belonging to a single symbol
     */
    private function matchSymbol(array $trades): FifoResult
    {
        // Stable sort by date: trades on the same day keep their file order,
        // which is the only ordering information a broker export gives us.
        usort($trades, static fn (Trade $a, Trade $b) => $a->date <=> $b->date);

        /** @var list<OpenLot> $openLots */
        $openLots = [];
        $matches = [];
        $unmatched = [];

        // Counted per symbol, not per run: the ordinal only has to separate two
        // otherwise identical matches of *this* queue. Numbering across symbols
        // would make one instrument's lineage - and therefore the fingerprint
        // that decides duplicates - move whenever an unrelated instrument was
        // added to the upload.
        $sequence = 0;

        foreach ($trades as $trade) {
            if ($trade->isBuy()) {
                $openLots[] = new OpenLot($trade);

                continue;
            }

            if (!$trade->isSell()) {
                // Zero-quantity rows (corporate action placeholders) carry no
                // cost basis information and are ignored.
                continue;
            }

            $sellQtyLeft = $trade->quantity->abs();
            $sellQtyTotal = $sellQtyLeft;
            $sellAmountLeft = $trade->grossAmount->abs();
            $sellCommissionLeft = $trade->commission?->abs();
            $sellAutoFxLeft = $trade->autoFx?->abs();

            foreach ($openLots as $lot) {
                if (!$sellQtyLeft->isPositive()) {
                    break;
                }

                if (!$lot->remainingQuantity->isPositive()) {
                    continue;
                }

                $matchedQty = $lot->remainingQuantity->min($sellQtyLeft);

                $lotQtyBefore = $lot->remainingQuantity;
                $buyCost = $lot->take($matchedQty);
                [$buyCommission, $buyAutoFx] = $lot->takeFees($matchedQty, $lotQtyBefore);
                $sellProceeds = $this->slice(
                    $sellAmountLeft,
                    $matchedQty,
                    $sellQtyLeft,
                    $sellQtyTotal,
                    $trade->grossAmount->abs(),
                );
                $sellCommission = $this->sliceOptional(
                    $trade->commission,
                    $sellCommissionLeft,
                    $matchedQty,
                    $sellQtyLeft,
                    $sellQtyTotal,
                );
                $sellAutoFx = $this->sliceOptional(
                    $trade->autoFx,
                    $sellAutoFxLeft,
                    $matchedQty,
                    $sellQtyLeft,
                    $sellQtyTotal,
                );

                $matches[] = new FifoMatch(
                    $trade->symbol,
                    $lot->trade->date,
                    $trade->date,
                    $matchedQty,
                    $buyCost,
                    $sellProceeds,
                    $lot->trade->externalId,
                    $trade->externalId,
                    $lot->trade->source,
                    $trade->source,
                    ++$sequence,
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
                );

                $sellQtyLeft = $sellQtyLeft->minus($matchedQty);
                $sellAmountLeft = $sellAmountLeft->minus($sellProceeds);
                if (null !== $sellCommissionLeft && null !== $sellCommission) {
                    $sellCommissionLeft = $sellCommissionLeft->minus($sellCommission);
                }
                if (null !== $sellAutoFxLeft && null !== $sellAutoFx) {
                    $sellAutoFxLeft = $sellAutoFxLeft->minus($sellAutoFx);
                }
            }

            if ($sellQtyLeft->isPositive()) {
                $unmatched[] = new UnmatchedSell($trade->symbol, $trade->date, $sellQtyLeft, $trade->id());
            }
        }

        return new FifoResult($matches, $unmatched);
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

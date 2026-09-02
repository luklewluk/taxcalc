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

        foreach ($bySymbol as $symbolTrades) {
            $result = $this->matchSymbol($symbolTrades);
            $matches = [...$matches, ...$result->matches];
            $unmatched = [...$unmatched, ...$result->unmatchedSells];
        }

        return new FifoResult($matches, $unmatched);
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

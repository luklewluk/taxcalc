<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Money\Amount;
use App\Money\Decimal;

/**
 * Mutable bookkeeping for a buy lot while it is being consumed by sells.
 *
 * @internal used only by {@see FifoMatcher}
 */
final class OpenLot
{
    public Decimal $remainingQuantity;

    public Amount $remainingAmount;

    public ?Amount $remainingCommission;

    public ?Amount $remainingAutoFx;

    public function __construct(public readonly Trade $trade)
    {
        $this->remainingQuantity = $trade->quantity->abs();
        $this->remainingAmount = $trade->grossAmount->abs();
        $this->remainingCommission = $trade->commission?->abs();
        $this->remainingAutoFx = $trade->autoFx?->abs();
    }

    /**
     * Consume `$quantity` from this lot and return the matching cost basis.
     *
     * The final slice returns the exact remaining balance, so repeated partial
     * takes always add up to the lot total.
     */
    public function take(Decimal $quantity): Amount
    {
        if (0 === $quantity->compareTo($this->remainingQuantity)) {
            $cost = $this->remainingAmount;
            $this->remainingQuantity = Decimal::zero();
            $this->remainingAmount = Amount::zero($cost->currency());

            return $cost;
        }

        $cost = $this->trade->grossAmount->abs()->proratedBy($quantity, $this->trade->quantity->abs());

        $this->remainingQuantity = $this->remainingQuantity->minus($quantity);
        $this->remainingAmount = $this->remainingAmount->minus($cost);

        return $cost;
    }

    /** @return array{?Amount, ?Amount} audit fee slices for the same quantity */
    public function takeFees(Decimal $quantity, Decimal $quantityBeforeTake): array
    {
        return [
            $this->takeOptional($this->remainingCommission, $this->trade->commission, $quantity, $quantityBeforeTake),
            $this->takeOptional($this->remainingAutoFx, $this->trade->autoFx, $quantity, $quantityBeforeTake),
        ];
    }

    private function takeOptional(
        ?Amount &$remaining,
        ?Amount $total,
        Decimal $quantity,
        Decimal $quantityBeforeTake,
    ): ?Amount {
        if (null === $remaining || null === $total) {
            return null;
        }

        if (0 === $quantity->compareTo($quantityBeforeTake)) {
            $slice = $remaining;
            $remaining = Amount::zero($slice->currency());

            return $slice;
        }

        $slice = $total->abs()->proratedBy($quantity, $this->trade->quantity->abs());

        // Every non-final slice is rounded half-up from the *whole* fee, so
        // their sum can overshoot it: 0.02 over four one-share sells rounds to
        // 0.01 three times and would leave -0.01 for the last one. Capping at
        // what is left keeps every slice non-negative while the final slice
        // still receives the exact remainder. Capping rather than prorating the
        // remainder on purpose - it leaves the ordinary splits untouched.
        if ($slice->compareTo($remaining) > 0) {
            $slice = $remaining;
        }

        $remaining = $remaining->minus($slice);

        return $slice;
    }
}

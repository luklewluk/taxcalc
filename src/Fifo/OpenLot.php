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

    public function __construct(public readonly Trade $trade)
    {
        $this->remainingQuantity = $trade->quantity->abs();
        $this->remainingAmount = $trade->grossAmount->abs();
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
}

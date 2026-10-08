<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\CurrencyRate\ExchangedAmount;
use App\Model\ClosedPosition;
use App\Money\Amount;

/**
 * One closed position with both legs converted to PLN.
 *
 * `$cost` is the acquisition cost alone, converted at the buy date's rate.
 * `$disposalCost` is the fee the broker took out of the proceeds, converted at
 * the *sell* date's rate - two rates, so they cannot share one
 * {@see ExchangedAmount}. `$revenue` is the gross amount due, i.e. the settled
 * proceeds with that same fee added back, which is what PIT-38 declares.
 */
final readonly class CalculatedPosition
{
    public function __construct(
        public ClosedPosition $position,
        public ExchangedAmount $cost,
        public ExchangedAmount $revenue,
        public Amount $income,
        /** `null` when the source reported no sell fee, so no split was possible. */
        public ?ExchangedAmount $disposalCost = null,
    ) {
    }

    /**
     * Everything PIT-38 counts as a cost for this position.
     *
     * Every display goes through this rather than adding the two itself: a null
     * disposal cost reaching Twig is a 500, and a row whose source reported no
     * sell fee is null.
     */
    public function totalCost(): Amount
    {
        return null === $this->disposalCost
            ? $this->cost->pln
            : $this->cost->pln->plus($this->disposalCost->pln);
    }

    public function isLoss(): bool
    {
        return $this->income->isNegative();
    }
}

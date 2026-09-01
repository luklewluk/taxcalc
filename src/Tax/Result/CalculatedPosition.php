<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\CurrencyRate\ExchangedAmount;
use App\Model\ClosedPosition;
use App\Money\Amount;

/**
 * One closed position with both legs converted to PLN.
 */
final readonly class CalculatedPosition
{
    public function __construct(
        public ClosedPosition $position,
        public ExchangedAmount $cost,
        public ExchangedAmount $revenue,
        public Amount $income,
    ) {
    }

    public function isLoss(): bool
    {
        return $this->income->isNegative();
    }
}

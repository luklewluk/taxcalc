<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Money\Decimal;
use LogicException;

/**
 * Part of a sale taken from one named buy lot.
 */
final readonly class LotAllocation
{
    public function __construct(
        public string $buyTradeId,
        public Decimal $quantity,
    ) {
        if ('' === $buyTradeId || !$quantity->isPositive()) {
            // The form codec validates first; reaching this is a programming error.
            throw new LogicException('A lot allocation names a lot and a positive quantity.');
        }
    }
}

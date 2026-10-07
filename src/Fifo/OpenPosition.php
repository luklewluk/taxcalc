<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Money\Decimal;

/**
 * What is still open of a trade once every sale in its queue has been matched:
 * shares (or bought contracts) still held, or written contracts not yet closed.
 * Informational only - nothing is taxed until the position closes.
 */
final readonly class OpenPosition
{
    public function __construct(
        public Trade $trade,
        public Decimal $quantity,
        public PositionDirection $direction,
    ) {
    }
}

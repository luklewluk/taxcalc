<?php

declare(strict_types=1);

namespace App\Web\Lots;

use App\Fifo\LotMethod;
use App\Money\Decimal;

/**
 * One line between an opening and a closing: which counterpart, how many shares.
 */
final readonly class LotLink
{
    public function __construct(
        public string $label,
        public string $tradeId,
        public Decimal $quantity,
        public LotMethod $method,
    ) {
    }
}

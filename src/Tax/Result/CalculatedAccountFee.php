<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\CurrencyRate\ExchangedAmount;
use App\Model\AccountFee;
use App\Money\Amount;

final readonly class CalculatedAccountFee
{
    public function __construct(
        public AccountFee $fee,
        public ExchangedAmount $exchanged,
        /** Positive for a charge, negative for a correction/refund. */
        public Amount $costImpact,
    ) {
    }
}

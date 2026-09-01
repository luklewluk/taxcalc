<?php

declare(strict_types=1);

namespace App\CurrencyRate;

use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;

/**
 * The original amount together with its PLN equivalent and the exact NBP
 * quotation that produced it, so every figure in a report stays auditable.
 */
final readonly class ExchangedAmount
{
    public function __construct(
        public Amount $original,
        public Amount $pln,
        public Decimal $rate,
        public ?DateTimeImmutable $rateDate,
        public ?string $rateTable = null,
    ) {
    }
}

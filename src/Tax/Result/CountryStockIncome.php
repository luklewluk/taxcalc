<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\Money\Amount;

/**
 * Per-country aggregate used to fill in the PIT/ZG attachment.
 */
final readonly class CountryStockIncome
{
    public function __construct(
        public string $countryCode,
        public ?string $countryName,
        public Amount $revenue,
        public Amount $cost,
        public Amount $income,
    ) {
    }
}

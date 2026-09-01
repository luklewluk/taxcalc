<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\Money\Amount;

/**
 * Per-country dividend aggregate for PIT/ZG, with both credit scenarios so the
 * attachment can be reconciled either way.
 */
final readonly class CountryDividendIncome
{
    public function __construct(
        public string $countryCode,
        public ?string $countryName,
        public Amount $gross,
        public Amount $withheldTax,
        public Amount $polishTax,
        public DividendCredit $conservative,
        public DividendCredit $nsa,
    ) {
    }
}

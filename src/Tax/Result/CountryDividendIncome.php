<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\Money\Amount;
use App\Tax\CreditMethod;

/**
 * Per-country dividend aggregate, with both credit scenarios so the return can
 * be reconciled either way.
 *
 * Not a PIT/ZG breakdown: dividends are taxed under art. 30a and reported in
 * part G of PIT-38, while PIT/ZG covers art. 27/30b/30c/30e. The country matters
 * here because it decides the treaty withholding cap in the conservative
 * scenario, not because an attachment is filed per country.
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

    /**
     * The credit under one reading, for a surface that shows only the chosen one.
     */
    public function creditFor(CreditMethod $method): DividendCredit
    {
        return CreditMethod::Conservative === $method ? $this->conservative : $this->nsa;
    }
}

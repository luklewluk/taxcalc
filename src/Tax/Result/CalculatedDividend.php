<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\CurrencyRate\ExchangedAmount;
use App\Model\Dividend;
use App\Money\Amount;
use App\Money\Decimal;
use App\Tax\CreditMethod;

/**
 * One dividend with both readings of the foreign-tax credit; a surface shows
 * the chosen one through {@see creditFor()}.
 *
 * `polishTax` and the credits are exact: they are summed before rounding, and
 * rounded only where a figure is declared or displayed.
 */
final readonly class CalculatedDividend
{
    public function __construct(
        public Dividend $dividend,
        public ExchangedAmount $gross,
        public ExchangedAmount $withheldTax,
        public Amount $polishTax,
        public DividendCredit $conservative,
        public DividendCredit $nsa,
        public ?Decimal $treatyPercent,
        public ?string $warning = null,
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

<?php

declare(strict_types=1);

namespace App\Tax\Result;

use App\CurrencyRate\ExchangedAmount;
use App\Model\Dividend;
use App\Money\Amount;
use App\Money\Decimal;

/**
 * One dividend with both readings of the foreign-tax credit.
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

    public function scenariosDiffer(): bool
    {
        return 0 !== $this->conservative->taxDue->toScale(2)->compareTo($this->nsa->taxDue->toScale(2));
    }
}

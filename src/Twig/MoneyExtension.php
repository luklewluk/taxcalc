<?php

declare(strict_types=1);

namespace App\Twig;

use App\Money\Amount;
use App\Money\Decimal;
use App\Web\Format\MoneyFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Exposes the Polish money notation to templates.
 *
 * Three filters, deliberately narrow:
 *
 *   {{ amount|money }}                 1 523,98
 *   {{ amount|money_with_currency }}    1 523,98 USD  /  16,00 zł
 *   {{ '1001.00'|money_text }}          1 001,00  (a posted form value; kept as typed if not a decimal)
 *
 * Only figures that really are money go through them. An NBP rate and a share
 * count are not amounts: they are quoted the way NBP and the broker quote them,
 * and localising them would invite a reader to compare them with a złoty total.
 */
final class MoneyExtension extends AbstractExtension
{
    public function __construct(private readonly MoneyFormatter $formatter)
    {
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('money_with_currency', $this->moneyWithCurrency(...)),
            new TwigFilter('money_text', $this->formatter->formatText(...)),
        ];
    }

    public function money(Amount|Decimal $value, ?int $scale = null): string
    {
        return $this->formatter->format($value, $scale);
    }

    public function moneyWithCurrency(Amount $amount, ?int $scale = null): string
    {
        return $this->formatter->formatWithCurrency($amount, $scale);
    }
}

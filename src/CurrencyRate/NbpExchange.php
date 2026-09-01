<?php

declare(strict_types=1);

namespace App\CurrencyRate;

use App\Money\Amount;
use App\Money\Decimal;
use DateTimeImmutable;

/**
 * Converts to PLN using the NBP average (table A) rate published on the last
 * business day before the transaction - the D-1 rule of art. 11a of the Polish
 * PIT act.
 *
 * Amounts already in PLN are passed through untouched and never trigger a
 * network lookup.
 */
final readonly class NbpExchange implements ExchangeInterface
{
    public const string BASE_CURRENCY = 'PLN';

    /**
     * Polish tax figures are expressed in grosze.
     */
    private const int PLN_SCALE = 2;

    public function __construct(private NbpRateProviderInterface $rateProvider)
    {
    }

    public function toPln(Amount $amount, DateTimeImmutable $transactionDate): ExchangedAmount
    {
        if (self::BASE_CURRENCY === $amount->currency()) {
            return new ExchangedAmount(
                $amount,
                $amount->toScale(self::PLN_SCALE),
                Decimal::of(1),
                null,
                null,
            );
        }

        $rate = $this->rateProvider->rateForPreviousBusinessDay($amount->currency(), $transactionDate);

        $pln = Amount::fromDecimal(
            $amount->value()->multipliedBy($rate->rate)->toScale(self::PLN_SCALE),
            self::BASE_CURRENCY,
        );

        return new ExchangedAmount($amount, $pln, $rate->rate, $rate->date, $rate->table);
    }
}

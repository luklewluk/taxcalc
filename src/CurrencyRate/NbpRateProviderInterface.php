<?php

declare(strict_types=1);

namespace App\CurrencyRate;

use App\Exception\ExchangeRateUnavailableException;
use DateTimeImmutable;

interface NbpRateProviderInterface
{
    /**
     * Rate published on the last business day *before* `$transactionDate`.
     *
     * This is the D-1 rule required by the Polish PIT-38 settlement.
     *
     * @throws ExchangeRateUnavailableException
     */
    public function rateForPreviousBusinessDay(string $currency, DateTimeImmutable $transactionDate): NbpRate;
}

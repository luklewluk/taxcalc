<?php

declare(strict_types=1);

namespace App\CurrencyRate;

use App\Exception\ExchangeRateUnavailableException;
use App\Money\Amount;
use DateTimeImmutable;

interface ExchangeInterface
{
    /**
     * @throws ExchangeRateUnavailableException
     */
    public function toPln(Amount $amount, DateTimeImmutable $transactionDate): ExchangedAmount;
}

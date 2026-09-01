<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\CurrencyRate\ExchangeInterface;
use App\CurrencyRate\NbpExchange;

/**
 * Convenience factory for a fully offline {@see ExchangeInterface}.
 */
final class FixedExchange
{
    public static function create(): ExchangeInterface
    {
        return new NbpExchange(new FixedNbpRateProvider());
    }
}

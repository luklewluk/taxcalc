<?php

declare(strict_types=1);

namespace App\Web;

/**
 * Which tab a country group belongs to.
 *
 * The two are never merged: a dividend declares the residence of the payer and a
 * trade the place of disposal, so the same instrument can and often does carry
 * two different countries. See {@see CountryGroup}.
 */
enum CountryScope: string
{
    case Trades = 'trades';
    case Dividends = 'dividends';

    public function tab(): string
    {
        return match ($this) {
            self::Trades => 'transactions',
            self::Dividends => 'dividends',
        };
    }
}

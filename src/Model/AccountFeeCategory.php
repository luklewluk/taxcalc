<?php

declare(strict_types=1);

namespace App\Model;

/**
 * What a standalone account fee is for. The value is the Polish label - it is
 * what the form posts, the CSV prints and the Opłaty tab shows.
 */
enum AccountFeeCategory: string
{
    case ExchangeConnection = 'Połączenie z giełdą';
    case Account = 'Prowadzenie rachunku';
    case MarketData = 'Dane rynkowe';
    case Transfers = 'Przelewy i wypłaty';
    case Other = 'Inne';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}

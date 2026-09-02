<?php

declare(strict_types=1);

namespace App\Web;

/**
 * Where the country of income for a *trade* is taken from.
 *
 * Two readings of the same question are defensible for the disposal of shares,
 * and Polish practice has not settled between them: the country where the
 * income arose can be read as the country of the exchange the sale happened on,
 * or as the country the instrument is registered in. This is therefore a user
 * choice, not a data defect - which is why it belongs in the settings panel and
 * must never be reported as something to fix.
 *
 * It applies to trades only. A dividend's country is the residence of the payer,
 * because that is what the double-taxation treaty follows when it caps the
 * withholding credit, and the DEGIRO account statement names no venue at all.
 */
enum CountrySource: string
{
    case Exchange = 'exchange';
    case Isin = 'isin';

    public function label(): string
    {
        return match ($this) {
            self::Exchange => 'Kraj giełdy notowania',
            self::Isin => 'Kraj rejestracji z numeru ISIN',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Exchange => 'Dochodem ze zbycia jest dochód osiągnięty na giełdzie, na której '
                .'sprzedano papier - sprzedaż we Frankfurcie to dochód z Niemiec. Kalkulator czyta '
                .'kolumnę giełdy z pliku transakcji; gdy plik jej nie podaje, pole zostaje puste.',
            self::Isin => 'Dochodem ze zbycia jest dochód z kraju rejestracji instrumentu - '
                .'irlandzki ETF sprzedany we Frankfurcie to dochód z Irlandii. Kalkulator czyta '
                .'dwa pierwsze znaki numeru ISIN; prefiksy, które nie są kodem kraju (XS, EU, QZ), '
                .'zostawiają pole puste.',
        };
    }
}

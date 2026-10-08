<?php

declare(strict_types=1);

namespace App\Tax;

/**
 * Whether standalone account fees - a broker's yearly charge for exchange
 * access or for keeping the account - reach the costs of PIT-38. They belong
 * to no single trade, and the law does not plainly say they are a cost of the
 * disposal, so they stay out until the user decides otherwise.
 */
enum AccountFeeTreatment: string
{
    case Excluded = 'excluded';
    case Included = 'included';

    public function label(): string
    {
        return match ($this) {
            self::Excluded => 'Nie wliczaj do kosztów',
            self::Included => 'Wliczaj do kosztów',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Excluded => 'Opłaty z zakładki Opłaty są pokazane, ale nie zmieniają kosztów w PIT-38.',
            self::Included => 'Opłaty z zakładki Opłaty, przeliczone kursem NBP z dnia roboczego przed ich '
                .'pobraniem, powiększają koszty w PIT-38, bez przypisania do kraju w PIT/ZG. Pojedynczą '
                .'opłatę można wyłączyć w zakładce Opłaty.',
        };
    }

    public function isCost(): bool
    {
        return self::Included === $this;
    }
}

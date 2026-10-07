<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Money\Decimal;
use DateTimeImmutable;

/**
 * The part of a sell that had no corresponding buy in the imported data.
 *
 * Typically means the opening transactions live in an earlier statement that
 * has not been uploaded. It has no cost basis, so it is left out of the result
 * - never silently: importers and the workbench report it as a review item, and
 * the rest of the data stays usable.
 */
final readonly class UnmatchedSell
{
    public function __construct(
        public string $symbol,
        public DateTimeImmutable $date,
        public Decimal $quantity,
        public string $tradeId = '',
    ) {
    }

    public const string CODE = 'fifo.unmatched_sell';

    public function describe(): string
    {
        return sprintf(
            'Sprzedaż %s z dnia %s (%s szt.) nie ma pokrycia w zakupach z wgranych plików i została '
            .'pominięta w rozliczeniu. Najpewniej brakuje wyciągu za wcześniejszy rok: zacznij nowe '
            .'rozliczenie i wgraj wszystkie wyciągi od roku pierwszego zakupu tego papieru - brak '
            .'starszych zakupów może też zmienić koszt późniejszych sprzedaży. Możesz też dopisać zakup '
            .'ręcznie w zakładce Transakcje.',
            $this->symbol,
            $this->date->format('Y-m-d'),
            (string) $this->quantity,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Money\Decimal;
use DateTimeImmutable;

/**
 * One option trade that could not be matched. Reported, never thrown - like
 * {@see UnmatchedSell}, the importer or the workbench turns it into a blocking
 * message.
 */
final readonly class FifoViolation
{
    public function __construct(
        public FifoViolationKind $kind,
        public string $symbol,
        public DateTimeImmutable $date,
        public Decimal $quantity,
        public string $tradeId = '',
    ) {
    }

    public function describe(): string
    {
        $day = $this->date->format('Y-m-d');

        return match ($this->kind) {
            FifoViolationKind::UnmatchedClose => sprintf(
                'Zamknięcie opcji %s z dnia %s (%s szt.) nie ma otwarcia w wgranych plikach i zostało '
                .'pominięte w rozliczeniu. Najpewniej brakuje wyciągu za wcześniejszy rok: zacznij nowe '
                .'rozliczenie i wgraj wszystkie wyciągi od roku otwarcia tej opcji.',
                $this->symbol,
                $day,
                (string) $this->quantity,
            ),
            FifoViolationKind::OpenAgainstOpposite => sprintf(
                'Otwarcie %s z dnia %s następuje przy otwartej pozycji przeciwnej - transakcja zamyka ją '
                .'zamiast otwierać. Popraw rodzaj transakcji na „Zamknięcie”.',
                $this->symbol,
                $day,
            ),
            FifoViolationKind::MixedInstrumentKinds => sprintf(
                'Kolejka %s zawiera jednocześnie akcje i opcje (%s). Popraw rodzaj instrumentu.',
                $this->symbol,
                $day,
            ),
            FifoViolationKind::MissingEffect => sprintf(
                'Dla opcji %s z dnia %s wskaż, czy transakcja otwiera, czy zamyka pozycję.',
                $this->symbol,
                $day,
            ),
        };
    }
}

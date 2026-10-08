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
    private const string LOT_FIX = 'Zmień partie albo przywróć FIFO (zakładka FIFO).';

    public function __construct(
        public FifoViolationKind $kind,
        public string $symbol,
        public DateTimeImmutable $date,
        public Decimal $quantity,
        public string $tradeId = '',
        /** Named-lot problems: the lot, what was asked of it and what it held. */
        public ?DateTimeImmutable $lotDate = null,
        public ?Decimal $requested = null,
        public ?Decimal $available = null,
        public string $lotTradeId = '',
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
            FifoViolationKind::LotMissing => sprintf(
                'Sprzedaż %s z dnia %s wskazuje partię, której nie ma wśród transakcji (usunięta?). %s',
                $this->symbol,
                $day,
                self::LOT_FIX,
            ),
            FifoViolationKind::LotNotEligible => sprintf(
                'Sprzedaż %s z dnia %s wskazuje partię innego instrumentu, brokera albo waluty. %s',
                $this->symbol,
                $day,
                self::LOT_FIX,
            ),
            FifoViolationKind::LotNotYetOpen => sprintf(
                'Sprzedaż %s z dnia %s wskazuje partię kupioną %s, czyli po tej sprzedaży. %s',
                $this->symbol,
                $day,
                $this->lotDate?->format('Y-m-d') ?? '?',
                self::LOT_FIX,
            ),
            FifoViolationKind::LotInsufficient => sprintf(
                'Sprzedaż %s z dnia %s ma wskazane %s szt. z partii kupionej %s, ale w chwili sprzedaży '
                .'zostało w niej %s szt. - resztę zamknęły wcześniejsze sprzedaże. %s',
                $this->symbol,
                $day,
                (string) $this->requested,
                $this->lotDate?->format('Y-m-d') ?? '?',
                (string) $this->available,
                self::LOT_FIX,
            ),
            FifoViolationKind::LotQuantityMismatch => sprintf(
                'Sprzedaż %s z dnia %s obejmuje %s szt., a wskazane partie sumują się do %s szt. %s',
                $this->symbol,
                $day,
                (string) $this->quantity,
                (string) $this->requested,
                self::LOT_FIX,
            ),
            FifoViolationKind::AssignmentNotASale => sprintf(
                'Partie można wskazać tylko dla sprzedaży akcji lub ETF-ów, a transakcja %s z dnia %s nią nie jest. %s',
                $this->symbol,
                $day,
                self::LOT_FIX,
            ),
        };
    }
}

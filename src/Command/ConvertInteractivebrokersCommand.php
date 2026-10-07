<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\ImportResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Converts an Interactive Brokers trade export into the normalized
 * closed-positions CSV, matching buys to sells with FIFO.
 */
#[AsCommand(
    name: 'app:convert-interactivebrokers',
    description: 'Konwertuje zestawienie transakcji z Interactive Brokers na format własny',
    hidden: true,
)]
final class ConvertInteractivebrokersCommand extends AbstractConvertCommand
{
    protected function recordCount(ImportResult $result): int
    {
        return count(self::stockPositions($result));
    }

    /**
     * The own format has no kind and no direction: a written option would come
     * back as a stock settled in the year it was opened, and an expiry's zero
     * leg would not come back at all.
     */
    protected function render(ImportResult $result): string
    {
        return $this->csvWriter->writePositions(self::stockPositions($result));
    }

    protected function emptyMessage(): string
    {
        return 'Nie znaleziono żadnych zamkniętych pozycji do zapisania.';
    }

    protected function successMessage(): string
    {
        return 'Zapisano %d pozycji do %s.';
    }

    /**
     * Which reminder the user gets depends on what the input could tell us.
     *
     * Flat IBKR exports carry no country at all, so the column comes out blank.
     * DEGIRO names the exchange, so the column comes out filled with the country
     * the paper is *listed* in - which still has to be checked, and saying it is
     * empty would be plainly wrong.
     */
    protected function afterWrite(SymfonyStyle $io, ImportResult $result): void
    {
        $options = count($result->positions) - count(self::stockPositions($result));
        if ($options > 0) {
            $io->warning(sprintf(
                'Pominięto %d pozycj(ę/e/i) opcyjn(ą/e/ych) - format własny nie ma dla nich reprezentacji. '
                .'Rozlicz je przez app:calculate-from-file albo w aplikacji WWW.',
                $options,
            ));
        }

        foreach (self::stockPositions($result) as $position) {
            if ('' === $position->countryCode) {
                $io->note(
                    'Kolumna "country" jest pusta - uzupełnij kraj uzyskania dochodu przed rozliczeniem PIT/ZG.',
                );

                return;
            }
        }

        $io->note(
            'Kolumna "country" została wypełniona na podstawie giełdy notowania podanej w pliku. To kraj '
            .'notowania papieru, nie zawsze kraj źródła dochodu - sprawdź ją przed rozliczeniem PIT/ZG.',
        );
    }

    /**
     * @return list<\App\Model\ClosedPosition>
     */
    private static function stockPositions(ImportResult $result): array
    {
        return array_values(array_filter(
            $result->positions,
            static fn (\App\Model\ClosedPosition $position): bool => !$position->isOption(),
        ));
    }
}

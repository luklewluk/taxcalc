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
)]
final class ConvertInteractivebrokersCommand extends AbstractConvertCommand
{
    protected function recordCount(ImportResult $result): int
    {
        return count($result->positions);
    }

    protected function render(ImportResult $result): string
    {
        return $this->csvWriter->writePositions($result->positions);
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
     * DEGIRO carries an ISIN, so the column comes out filled with the country
     * the *security is registered in* - which still has to be checked, and
     * saying it is empty would be plainly wrong.
     */
    protected function afterWrite(SymfonyStyle $io, ImportResult $result): void
    {
        foreach ($result->positions as $position) {
            if ('' === $position->countryCode) {
                $io->note(
                    'Kolumna "country" jest pusta - uzupełnij kraj uzyskania dochodu przed rozliczeniem PIT/ZG.',
                );

                return;
            }
        }

        $io->note(
            'Kolumna "country" została wypełniona na podstawie prefiksu numeru ISIN. To kraj rejestracji '
            .'papieru, nie zawsze kraj źródła dochodu - sprawdź ją przed rozliczeniem PIT/ZG.',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\ImportResult;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Converts an Interactive Brokers dividend export - either the flat activity
 * export or the sectioned Dividend Detail statement - into the normalized
 * dividends CSV.
 */
#[AsCommand(
    name: 'app:convert-dividends-interactivebrokers',
    description: 'Konwertuje zestawienie dywidend z Interactive Brokers na format własny',
    hidden: true,
)]
final class ConvertDividendsInteractivebrokersCommand extends AbstractConvertCommand
{
    protected function recordCount(ImportResult $result): int
    {
        return count($result->dividends);
    }

    protected function render(ImportResult $result): string
    {
        return $this->csvWriter->writeDividends($result->dividends);
    }

    protected function emptyMessage(): string
    {
        return 'Nie znaleziono żadnych dywidend do zapisania.';
    }

    protected function successMessage(): string
    {
        return 'Zapisano %d dywidend do %s.';
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\CsvImportService;
use App\Report\TaxReportBuilder;
use App\Tax\TaxYearFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Full PIT-38 settlement from one or more CSV files.
 *
 * Backwards compatible with the original single-file usage against the
 * normalized positions format; it now also accepts several files at once and
 * any of the supported broker exports.
 */
#[AsCommand(
    name: 'app:calculate-from-file',
    description: 'Oblicza podatek PIT-38 (akcje i dywidendy) na podstawie plików CSV',
)]
final class CalculateFromFileCommand extends Command
{
    public function __construct(
        private readonly CsvFileLoader $fileLoader,
        private readonly CsvImportService $importService,
        private readonly TaxReportBuilder $reportBuilder,
        private readonly TaxYearFilter $taxYearFilter,
        private readonly ReportRenderer $renderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'filepath',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'Ścieżki do plików CSV (format własny lub eksport Interactive Brokers)',
            )
            ->addOption(
                'rok',
                'r',
                InputOption::VALUE_REQUIRED,
                'Rok podatkowy do rozliczenia (domyślnie najnowszy rok obecny w danych)',
            )
            ->setHelp(<<<'HELP'
                Przykłady:

                  <info>php bin/console app:calculate-from-file pozycje.csv</info>
                  <info>php bin/console app:calculate-from-file transakcje.csv dywidendy.csv --rok=2024</info>

                Obsługiwane formaty są wykrywane automatycznie. Zakupy z lat wcześniejszych
                są używane jako koszt dla sprzedaży w rozliczanym roku.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $paths = $input->getArgument('filepath');

        [$sources, $fileErrors] = $this->fileLoader->load($paths);
        foreach ($fileErrors as $error) {
            $io->error($error);
        }

        if ([] !== $fileErrors || [] === $sources) {
            return Command::FAILURE;
        }

        $result = $this->importService->import($sources);

        foreach ($result->errors() as $error) {
            $io->warning($error);
        }
        foreach ($result->warnings() as $warning) {
            $io->note($warning);
        }

        if ($result->isEmpty()) {
            $io->error('Nie znaleziono żadnych transakcji ani dywidend do rozliczenia.');

            return Command::FAILURE;
        }

        $year = $this->resolveYear($input, $result->positions, $result->dividends);

        $io->title(sprintf('Rozliczenie PIT-38 za rok %d', $year));

        $report = $this->reportBuilder->build($result->positions, $result->dividends, $year);

        if ([] !== $report->errors) {
            $this->renderer->renderMessages($io, $report);
            $this->renderer->renderDisclaimer($io);

            return Command::FAILURE;
        }

        $this->renderer->renderStock($io, $report);
        $this->renderer->renderDividends($io, $report);
        $this->renderer->renderMessages($io, $report);

        $this->renderer->renderTotals($io, $report);
        $this->renderer->renderDisclaimer($io);

        return Command::SUCCESS;
    }

    /**
     * @param list<\App\Model\ClosedPosition> $positions
     * @param list<\App\Model\Dividend>       $dividends
     */
    private function resolveYear(InputInterface $input, array $positions, array $dividends): int
    {
        $option = $input->getOption('rok');
        if (is_string($option) && '' !== $option) {
            return (int) $option;
        }

        $years = $this->taxYearFilter->availableYears($positions, $dividends);

        return $years[0] ?? (int) date('Y') - 1;
    }
}

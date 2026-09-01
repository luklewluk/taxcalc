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
 * Dividend-only settlement, kept for backwards compatibility with the original
 * command name and the normalized dividends CSV.
 */
#[AsCommand(
    name: 'app:calculate-dividends-from-file',
    description: 'Oblicza podatek od dywidend (PIT-38 część G) na podstawie plików CSV',
)]
final class CalculateDividendsFromFileCommand extends Command
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
                'Ścieżki do plików CSV z dywidendami',
            )
            ->addOption(
                'rok',
                'r',
                InputOption::VALUE_REQUIRED,
                'Rok podatkowy do rozliczenia (domyślnie najnowszy rok obecny w danych)',
            )
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

        if ([] === $result->dividends) {
            $io->error('Nie znaleziono żadnych dywidend do rozliczenia.');

            return Command::FAILURE;
        }

        $option = $input->getOption('rok');
        $year = is_string($option) && '' !== $option
            ? (int) $option
            : ($this->taxYearFilter->availableYears([], $result->dividends)[0] ?? (int) date('Y') - 1);

        $io->title(sprintf('Dywidendy — rozliczenie za rok %d', $year));

        $report = $this->reportBuilder->build([], $result->dividends, $year);

        if ([] !== $report->errors) {
            $this->renderer->renderMessages($io, $report);
            $this->renderer->renderDisclaimer($io);

            return Command::FAILURE;
        }

        $this->renderer->renderDividends($io, $report);
        $this->renderer->renderMessages($io, $report);
        $this->renderer->renderDisclaimer($io);

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\CsvImportService;
use App\Import\ImportResult;
use App\Report\NormalizedCsvWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared skeleton for the two "convert a broker export into our own CSV"
 * commands: read one input file, import it, write the normalized result.
 *
 * Subclasses only decide which part of the import they care about and how to
 * render it.
 */
abstract class AbstractConvertCommand extends Command
{
    public function __construct(
        private readonly CsvFileLoader $fileLoader,
        private readonly CsvImportService $importService,
        protected readonly NormalizedCsvWriter $csvWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('input_path', InputArgument::REQUIRED, 'Ścieżka do pliku CSV z Interactive Brokers')
            ->addArgument('output_path', InputArgument::REQUIRED, 'Ścieżka do pliku wynikowego (zostanie nadpisany)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->warning('Polecenie app:* jest przestarzałe i zostanie usunięte w przyszłej wersji; użyj workbencha WWW.');

        $inputPath = self::stringArgument($input, 'input_path');
        $outputPath = self::stringArgument($input, 'output_path');

        [$sources, $fileErrors] = $this->fileLoader->load([$inputPath]);
        foreach ($fileErrors as $error) {
            $io->error($error);
        }

        if ([] === $sources) {
            return Command::FAILURE;
        }

        $result = $this->importService->import($sources);

        foreach ($result->errors() as $error) {
            $io->error($error);
        }
        foreach ($result->warnings() as $warning) {
            $io->warning($warning);
        }

        $count = $this->recordCount($result);
        if (0 === $count) {
            $io->error($this->emptyMessage());

            return Command::FAILURE;
        }

        if (!self::write($outputPath, $this->render($result))) {
            $io->error(sprintf('Nie powiódł się zapis do pliku "%s".', $outputPath));

            return Command::FAILURE;
        }

        $io->success(sprintf($this->successMessage(), $count, $outputPath));
        $this->afterWrite($io, $result);

        return Command::SUCCESS;
    }

    abstract protected function recordCount(ImportResult $result): int;

    abstract protected function render(ImportResult $result): string;

    abstract protected function emptyMessage(): string;

    /**
     * A `sprintf` template receiving the record count and the output path.
     */
    abstract protected function successMessage(): string;

    protected function afterWrite(SymfonyStyle $io, ImportResult $result): void
    {
    }

    private static function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        if (!is_string($value) || '' === trim($value)) {
            throw new InvalidArgumentException(sprintf('Argument "%s" musi być niepustą ścieżką.', $name));
        }

        return $value;
    }

    private static function write(string $path, string $content): bool
    {
        $directory = \dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            return false;
        }

        return false !== @file_put_contents($path, $content);
    }
}

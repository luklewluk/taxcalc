<?php

declare(strict_types=1);

namespace App\Controller;

use App\Fifo\FifoMatch;
use App\Fifo\Trade;
use App\Import\CsvImportService;
use App\Import\Degiro\ExchangeCountry;
use App\Import\ImportResult;
use App\Import\MessageLevel;
use App\Exception\InvalidRecordException;
use App\Model\AccountFee;
use App\Model\ClosedPosition;
use App\Model\CountryCode;
use App\Model\Dividend;
use App\Report\CsvReportWriter;
use App\Report\TaxReport;
use App\Report\TaxReportBuilder;
use App\Tax\CreditMethod;
use App\Tax\TaxRates;
use App\Web\CountryGroupAction;
use App\Web\CountryReview;
use App\Web\CountryScope;
use App\Web\CountrySourceApplier;
use App\Web\Diagnostic;
use App\Web\DiagnosticLevel;
use App\Web\RowFormMapper;
use App\Web\SettingsProvider;
use App\Web\TaxFormMap;
use App\Web\TaxYearProvider;
use App\Web\Upload\UploadedCsvReader;
use App\Web\WorkbenchCalculator;
use App\Web\WorkbenchSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stateless calculator workbench. Financial records are round-tripped in the
 * current form only; no record is written to a session, database or local file.
 *
 * @phpstan-type FormRow array<string, string>
 * @phpstan-type WorkbenchState array{
 *     year: int,
 *     trades: list<Trade>,
 *     dividends: list<Dividend>,
 *     fees: list<AccountFee>,
 *     legacyPositions: list<ClosedPosition>,
 *     tradeRows: list<FormRow>,
 *     dividendRows: list<FormRow>,
 *     feeRows: list<FormRow>,
 *     legacyRows: list<FormRow>,
 *     compatRows: list<FormRow>,
 *     tombstones: list<string>,
 *     errors: list<string>,
 *     diagnostics: list<Diagnostic>,
 *     settings: WorkbenchSettings
 * }
 * @phpstan-type WorkbenchContext array{
 *     report: TaxReport|null,
 *     matches: list<FifoMatch>,
 *     form_map: array<string, int|string|bool>,
 *     tax_years: list<int>,
 *     selected_year: int,
 *     csrf_token_id: string,
 *     countries: array<string, string>,
 *     trade_rows: list<FormRow>,
 *     dividend_rows: list<FormRow>,
 *     fee_rows: list<FormRow>,
 *     legacy_rows: list<FormRow>,
 *     compat_position_rows: list<FormRow>,
 *     tombstones: list<string>,
 *     errors: list<string>,
 *     diagnostics: list<Diagnostic>,
 *     has_blocking: bool,
 *     attention_count: int,
 *     active_tab: string,
 *     trade_country_groups: array<int, array{group_id: string, empty_count: int}>,
 *     dividend_country_groups: array<int, array{group_id: string, empty_count: int}>,
 *     country_group_actions: array<string, CountryGroupAction>,
 *     country_options: array<string, string>,
 *     settings: WorkbenchSettings,
 *     country_sources: array<string, string>,
 *     credit_methods: array<string, string>,
 *     country_source_help: array<string, string>,
 *     credit_method_help: array<string, string>,
 *     expected_trades: int,
 *     expected_dividends: int,
 *     expected_fees: int,
 *     expected_positions: int,
 *     disclaimer: string
 * }
 * @phpstan-type PreparedWorkbench array{state: WorkbenchState, context: WorkbenchContext}
 */
final class CalculatorController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'kalkulator';

    public function __construct(
        private readonly UploadedCsvReader $uploadedCsvReader,
        private readonly CsvImportService $importService,
        private readonly RowFormMapper $rowFormMapper,
        private readonly WorkbenchCalculator $workbenchCalculator,
        private readonly TaxReportBuilder $reportBuilder,
        private readonly CsvReportWriter $csvReportWriter,
        private readonly TaxYearProvider $taxYearProvider,
        private readonly TaxFormMap $taxFormMap,
        private readonly CountryReview $countryReview,
        private readonly SettingsProvider $settingsProvider,
        private readonly CountrySourceApplier $countrySourceApplier,
        private readonly TaxRates $taxRates,
        private readonly int $maxFiles,
        private readonly int $maxBytes,
        private readonly string $examplesDir,
    ) {
    }

    #[Route('/kalkulator', name: 'app_calculator', methods: ['GET'])]
    public function index(#[MapQueryParameter] ?int $rok = null): Response
    {
        return $this->render('calculator/index.html.twig', [
            'tax_years' => $this->taxYearProvider->years(),
            'selected_year' => $this->taxYearProvider->normalize($rok),
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'example_files' => array_filter(
                ExampleFileController::FILES,
                fn (array $meta, string $name): bool => !in_array($name, ['pozycje-zamkniete.csv', 'dywidendy.csv'], true)
                    && is_file($this->examplesDir.'/'.$meta[0]),
                ARRAY_FILTER_USE_BOTH,
            ),
            'max_files' => $this->maxFiles,
            'max_megabytes' => round($this->maxBytes / 1024 / 1024, 1),
        ]);
    }

    #[Route('/kalkulator/import', name: 'app_calculator_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $this->assertCsrfToken($request);
        $year = $this->taxYearProvider->normalize($request->request->get('tax_year'));
        $settings = $this->settingsProvider->normalize(
            $request->request->get('country_source'),
            $request->request->get('credit_method'),
        );
        $hasCurrentState = [] !== $request->request->all('trades')
            || [] !== $request->request->all('dividends')
            || [] !== $request->request->all('fees')
            || [] !== $request->request->all('positions')
            || [] !== $request->request->all('tombstones')
            || $request->request->has('expected_trades');

        $state = $hasCurrentState ? $this->readSubmission($request) : $this->emptyState($year);
        $uploads = $this->uploadedFiles($request);
        $read = $this->uploadedCsvReader->read($uploads);

        if ([] !== $read->rejections) {
            foreach ($read->rejectionMessages() as $message) {
                $state['errors'][] = $message;
                $state['diagnostics'][] = Diagnostic::blocking('upload.rejected', $message, 'transactions');
            }

            return $this->workbenchResponse($state);
        }

        if ([] === $uploads) {
            $message = 'Nie wybrano żadnego pliku. Wskaż co najmniej jeden plik CSV.';
            $state['errors'][] = $message;
            $state['diagnostics'][] = Diagnostic::blocking('upload.missing', $message, 'transactions');

            return $this->workbenchResponse($state);
        }

        if ($hasCurrentState && [] !== $state['errors']) {
            $message = 'Najpierw popraw bieżące dane. Nowy batch nie został dodany, aby nie utracić wpisanych wartości.';
            $state['errors'][] = $message;
            $state['diagnostics'][] = Diagnostic::blocking('upload.current_state_invalid', $message, 'transactions');

            return $this->workbenchResponse($state);
        }

        $imported = $this->importService->import($read->sources);
        if ($imported->hasErrors()) {
            $state['errors'] = [...$state['errors'], ...$imported->errors()];
            $state['diagnostics'] = [...$state['diagnostics'], ...$this->importDiagnostics($imported)];

            return $this->workbenchResponse($state);
        }

        $state = $hasCurrentState
            ? $this->mergeIndependentBatch($state, $imported)
            : $this->stateFromImport($imported, $year, $settings);

        if ([] === $state['trades'] && [] === $state['legacyPositions'] && [] === $state['dividends'] && [] === $state['fees']) {
            $message = 'W przesłanych plikach nie znaleziono żadnych transakcji, dywidend ani opłat do rozliczenia.';
            $state['errors'][] = $message;
            $state['diagnostics'][] = Diagnostic::blocking('import.empty', $message, 'transactions');
        }

        return $this->workbenchResponse($state);
    }

    #[Route('/kalkulator/wynik', name: 'app_calculator_result', methods: ['POST'])]
    public function result(Request $request): Response
    {
        $this->assertCsrfToken($request);
        $state = $this->readSubmission($request);
        $prepared = $this->prepare($state);

        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            $context = $prepared['context'];

            $revision = $request->request->get('revision', 0);

            return $this->json([
                'version' => is_numeric($revision) ? (int) $revision : 0,
                'ok' => null !== $context['report'],
                'activeTab' => $context['has_blocking'] ? 'attention' : null,
                'fragments' => [
                    'summary' => $this->renderView('_partials/workbench_summary.html.twig', $context),
                    'fifo' => $this->renderView('_partials/fifo_matches.html.twig', $context),
                    'attention' => $this->renderView('_partials/workbench_attention.html.twig', $context),
                    'attentionCounter' => $this->renderView('_partials/workbench_attention_counter.html.twig', $context),
                    'dividendResults' => $this->renderView('_partials/dividend_results.html.twig', $context),
                    'messages' => $this->renderView('_partials/workbench_messages.html.twig', $context),
                    'counters' => $this->renderView('_partials/workbench_counters.html.twig', $context),
                ],
            ]);
        }

        return $this->render('calculator/workbench.html.twig', $prepared['context']);
    }

    #[Route('/kalkulator/raport.csv', name: 'app_calculator_report_csv', methods: ['POST'])]
    public function csvReport(Request $request): Response
    {
        $this->assertCsrfToken($request);
        $prepared = $this->prepare($this->readSubmission($request));
        $report = $prepared['context']['report'];
        if (null === $report) {
            return $this->render('calculator/workbench.html.twig', $prepared['context']);
        }

        $state = $prepared['state'];
        $response = new Response($this->csvReportWriter->write(
            $report,
            $state['trades'],
            $state['fees'],
            $state['dividends'],
            $state['settings']->creditMethod,
        ));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            'attachment',
            $this->csvReportWriter->filename($report),
        ));

        return $response;
    }

    #[Route('/kalkulator/raport', name: 'app_calculator_report_print', methods: ['POST'])]
    public function printableReport(Request $request): Response
    {
        $this->assertCsrfToken($request);
        $prepared = $this->prepare($this->readSubmission($request));
        if (null === $prepared['context']['report']) {
            return $this->render('calculator/workbench.html.twig', $prepared['context']);
        }

        return $this->render('report/print.html.twig', $prepared['context']);
    }

    /** @return WorkbenchState */
    private function emptyState(int $year): array
    {
        return [
            'year' => $year,
            'trades' => [], 'dividends' => [], 'fees' => [], 'legacyPositions' => [],
            'tradeRows' => [], 'dividendRows' => [], 'feeRows' => [], 'legacyRows' => [], 'compatRows' => [],
            'tombstones' => [], 'errors' => [], 'settings' => new WorkbenchSettings(),
            'diagnostics' => [],
        ];
    }

    /** @return WorkbenchState */
    private function stateFromImport(ImportResult $result, int $year, WorkbenchSettings $settings): array
    {
        return [
            'year' => $year,
            'trades' => $result->trades,
            'dividends' => $result->dividends,
            'fees' => $result->fees,
            'legacyPositions' => $result->legacyPositions,
            'tradeRows' => array_map($this->rowFormMapper->tradeToForm(...), $result->trades),
            'dividendRows' => array_map($this->rowFormMapper->dividendToForm(...), $result->dividends),
            'feeRows' => array_map($this->rowFormMapper->feeToForm(...), $result->fees),
            'legacyRows' => array_map($this->rowFormMapper->positionToForm(...), $result->legacyPositions),
            'compatRows' => array_map($this->rowFormMapper->positionToForm(...), $result->positions),
            'tombstones' => [],
            'errors' => $result->errors(),
            'diagnostics' => $this->importDiagnostics($result),
            'settings' => $settings,
        ];
    }

    /** @return list<Diagnostic> */
    private function importDiagnostics(ImportResult $result): array
    {
        $diagnostics = [];
        foreach ($result->messages as $message) {
            // Warnings and notices are deliberately rendered nowhere - a strip
            // that grows with every skipped row buries the one sentence that
            // matters. Review level is the importer's explicit "the user has to
            // act on this", so it earns an item in the panel. `targetTab` is not
            // the signal: CsvImportService stamps one onto every message of a
            // file (see resultWithMessageTarget()).
            if (MessageLevel::Review === $message->level) {
                $diagnostics[] = Diagnostic::review(
                    'import.review',
                    $message->describe(),
                    $message->targetTab ?? 'attention',
                );

                continue;
            }

            if (MessageLevel::Error !== $message->level) {
                continue;
            }
            $diagnostics[] = Diagnostic::blocking(
                'import.invalid',
                $message->describe(),
                $message->targetTab ?? 'attention',
            );
        }

        return $diagnostics;
    }

    /** @return WorkbenchState */
    private function readSubmission(Request $request): array
    {
        $year = $this->taxYearProvider->normalize($request->request->get('tax_year'));
        $rawTrades = $request->request->all('trades');
        $rawDividends = $request->request->all('dividends');
        $rawFees = $request->request->all('fees');
        $rawPositions = $request->request->all('positions');

        $settings = $this->settingsProvider->normalize(
            $request->request->get('country_source'),
            $request->request->get('credit_method'),
        );

        // Before the explicit actions on purpose: a click on a bulk button is a
        // deliberate answer and must win over a setting.
        $this->countrySourceApplier->apply($rawTrades, $settings->countrySource);

        [$bulkErrors, $bulkDiagnostics] = $this->applyBulkCountry($request, $rawTrades);
        [$groupErrors, $groupDiagnostics] = $this->applyCountryGroup($request, $rawTrades, $rawDividends);
        $this->applyCompatibilityCountries($rawTrades, $rawPositions);
        $legacyInput = [] === $rawTrades
            ? $rawPositions
            : array_values(array_filter($rawPositions, fn (mixed $row): bool => is_array($row) && '1' === $this->scalarString($row['legacy'] ?? null)));

        $trades = $this->rowFormMapper->mapTrades($rawTrades);
        $dividends = $this->rowFormMapper->mapDividends($rawDividends);
        $fees = $this->rowFormMapper->mapFees($rawFees);
        $legacy = $this->rowFormMapper->mapPositions($legacyInput);

        $tradeTruncation = $this->truncationErrors('transakcji', $request, 'expected_trades', count($rawTrades));
        $dividendTruncation = $this->truncationErrors('dywidend', $request, 'expected_dividends', count($rawDividends));
        $feeTruncation = $this->truncationErrors('opłat', $request, 'expected_fees', count($rawFees));
        $positionTruncation = $this->truncationErrors('pozycji legacy', $request, 'expected_positions', count($rawPositions));

        $errors = [
            ...$bulkErrors,
            ...$groupErrors,
            ...$tradeTruncation,
            ...$dividendTruncation,
            ...$feeTruncation,
            ...$positionTruncation,
            ...$trades->errors, ...$dividends->errors, ...$fees->errors, ...$legacy->errors,
        ];
        $diagnostics = [
            ...$bulkDiagnostics,
            ...$groupDiagnostics,
            ...$this->diagnosticsFor($tradeTruncation, 'form.truncated_trades', 'transactions'),
            ...$this->diagnosticsFor($dividendTruncation, 'form.truncated_dividends', 'dividends'),
            ...$this->diagnosticsFor($feeTruncation, 'form.truncated_fees', 'fees'),
            ...$this->diagnosticsFor($positionTruncation, 'form.truncated_positions', 'transactions'),
            ...$trades->diagnostics,
            ...$dividends->diagnostics,
            ...$fees->diagnostics,
            ...$legacy->diagnostics,
        ];

        return [
            'year' => $year,
            'trades' => $trades->trades,
            'dividends' => $dividends->dividends,
            'fees' => $fees->fees,
            'legacyPositions' => $legacy->positions,
            'tradeRows' => $trades->rows,
            'dividendRows' => $dividends->rows,
            'feeRows' => $fees->rows,
            'legacyRows' => $legacy->rows,
            'compatRows' => $this->compatibilityRows($rawPositions),
            'tombstones' => $this->submittedTombstones($request, [$rawTrades, $rawDividends, $rawFees, $legacyInput]),
            'errors' => $errors,
            'diagnostics' => $diagnostics,
            'settings' => $settings,
        ];
    }

    /**
     * @param WorkbenchState $state
     *
     * @return PreparedWorkbench
     */
    private function prepare(array $state): array
    {
        $countries = $this->countryReview->inspect($state['tradeRows'], $state['dividendRows']);
        $diagnostics = $this->uniqueDiagnostics([
            ...$state['diagnostics'],
            ...$countries->diagnostics,
            ...$this->treatyDiagnostics($state['dividends'], $state['year'], $state['settings']),
        ]);
        $settlement = null;
        $report = null;
        $hasBlocking = [] !== $state['errors'] || $this->hasBlocking($diagnostics);
        if (!$hasBlocking) {
            $settlement = $this->workbenchCalculator->settle($state['trades'], $state['legacyPositions']);
            $state['errors'] = [...$state['errors'], ...$settlement->errors];
            $diagnostics = $this->uniqueDiagnostics([...$diagnostics, ...$settlement->diagnostics]);
            $hasBlocking = [] !== $state['errors'] || $this->hasBlocking($diagnostics);
        }

        if (null !== $settlement && !$hasBlocking) {
            $candidate = $this->reportBuilder->build($settlement->positions, $state['dividends'], $state['year'], $state['fees']);
            $diagnostics = $this->uniqueDiagnostics([...$diagnostics, ...$candidate->diagnostics]);
            if ([] === $candidate->errors) {
                $report = $candidate;
            } else {
                $state['errors'] = [...$state['errors'], ...$candidate->errors];
            }
            $hasBlocking = [] !== $state['errors'] || $this->hasBlocking($diagnostics);
        }

        // The report builder emits the treaty-rate item too - it has no settings
        // and should not grow one for a presentation choice. Under the NSA
        // reading the treaty cap plays no part in the figure, so the item is
        // filtered out here rather than in the domain.
        if (CreditMethod::Conservative !== $state['settings']->creditMethod) {
            $diagnostics = array_values(array_filter(
                $diagnostics,
                static fn (Diagnostic $item): bool => 'dividend.treaty_rate_missing' !== $item->code,
            ));
        }

        $state['diagnostics'] = $diagnostics;

        $positions = null === $settlement ? [] : $settlement->positions;
        $context = [
            'report' => $report,
            'matches' => null === $settlement ? [] : $settlement->matches,
            'form_map' => $this->taxFormMap->forYear($state['year']),
            'tax_years' => $this->taxYearProvider->years(),
            'selected_year' => $state['year'],
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'countries' => $this->taxRates->supportedCountries(),
            'trade_rows' => $state['tradeRows'],
            'dividend_rows' => $state['dividendRows'],
            'fee_rows' => $state['feeRows'],
            'legacy_rows' => $state['legacyRows'],
            'compat_position_rows' => [] !== $positions
                ? array_map($this->rowFormMapper->positionToForm(...), $positions)
                : $state['compatRows'],
            'tombstones' => $state['tombstones'],
            'errors' => $state['errors'],
            'diagnostics' => $diagnostics,
            'has_blocking' => $hasBlocking,
            'attention_count' => count($diagnostics),
            'active_tab' => $hasBlocking ? 'attention' : 'summary',
            'trade_country_groups' => $countries->tradeGroups,
            'dividend_country_groups' => $countries->dividendGroups,
            'country_group_actions' => $countries->actions,
            'country_options' => $this->countryOptions($state['tradeRows'], $state['dividendRows'], $state['legacyRows']),
            'settings' => $state['settings'],
            'country_sources' => $this->settingsProvider->countrySources(),
            'credit_methods' => $this->settingsProvider->creditMethods(),
            'country_source_help' => $this->settingsProvider->countrySourceHelp(),
            'credit_method_help' => $this->settingsProvider->creditMethodHelp(),
            'expected_trades' => count($state['tradeRows']),
            'expected_dividends' => count($state['dividendRows']),
            'expected_fees' => count($state['feeRows']),
            'expected_positions' => count([] !== $positions ? $positions : $state['compatRows']),
            'disclaimer' => CsvReportWriter::DISCLAIMER,
        ];

        return ['state' => $state, 'context' => $context];
    }

    /** @param WorkbenchState $state */
    private function workbenchResponse(array $state): Response
    {
        $prepared = $this->prepare($state);

        return $this->render('calculator/workbench.html.twig', $prepared['context']);
    }

    /**
     * @param WorkbenchState $state
     *
     * @return WorkbenchState
     */
    private function mergeIndependentBatch(array $state, ImportResult $batch): array
    {
        $tombstones = array_fill_keys($state['tombstones'], true);
        $tradeIds = [];
        $queues = [];
        foreach ($state['trades'] as $trade) {
            $tradeIds[$trade->id()] = true;
            $queues[$this->queueKey($trade)] = true;
        }

        $newTrades = [];
        foreach ($batch->trades as $trade) {
            if (isset($tombstones[$trade->id()]) || isset($tradeIds[$trade->id()])) {
                continue;
            }
            $newTrades[] = $trade;
        }
        foreach ($newTrades as $trade) {
            if (isset($queues[$this->queueKey($trade)])) {
                $message = sprintf(
                    'Nowy batch dotyka istniejącej kolejki FIFO %s / %s. Cały upload odrzucono; dotychczasowa praca pozostała bez zmian.',
                    $trade->broker ?: 'broker',
                    $trade->fifoPool ?: $trade->symbol,
                );
                $state['errors'][] = $message;
                $state['diagnostics'][] = Diagnostic::blocking(
                    'upload.fifo_queue_conflict',
                    $message,
                    'transactions',
                    $trade->id(),
                    $this->queueKey($trade),
                );

                return $state;
            }
        }

        $addedRecords = count($newTrades);
        $state['trades'] = [...$state['trades'], ...$newTrades];
        $state['tradeRows'] = array_map($this->rowFormMapper->tradeToForm(...), $state['trades']);
        $before = count($state['dividends']);
        $state['dividends'] = $this->mergeById(
            $state['dividends'],
            $batch->dividends,
            static fn (Dividend $item): string => $item->id(),
            $tombstones,
        );
        $addedRecords += count($state['dividends']) - $before;
        $state['dividendRows'] = array_map($this->rowFormMapper->dividendToForm(...), $state['dividends']);
        $before = count($state['fees']);
        $state['fees'] = $this->mergeById(
            $state['fees'],
            $batch->fees,
            static fn (AccountFee $item): string => $item->id(),
            $tombstones,
        );
        $addedRecords += count($state['fees']) - $before;
        $state['feeRows'] = array_map($this->rowFormMapper->feeToForm(...), $state['fees']);
        $before = count($state['legacyPositions']);
        $state['legacyPositions'] = $this->mergeById(
            $state['legacyPositions'],
            $batch->legacyPositions,
            static fn (ClosedPosition $item): string => $item->fingerprint(),
            $tombstones,
        );
        $addedRecords += count($state['legacyPositions']) - $before;
        $state['legacyRows'] = array_map($this->rowFormMapper->positionToForm(...), $state['legacyPositions']);
        if (0 === $addedRecords) {
            // An upload that changed nothing is the one import notice that has
            // to survive: without it the user re-uploads a file and gets a page
            // that looks exactly the same, with no explanation.
            $state['diagnostics'][] = Diagnostic::review(
                'upload.nothing_added',
                'Wszystkie rekordy z dogranego pliku były już obecne albo wcześniej usunięte — niczego nie dodano.',
                'attention',
            );
        }

        return $state;
    }

    /**
     * @template T
     *
     * @param list<T>              $existing
     * @param list<T>              $incoming
     * @param callable(T): string  $id
     * @param array<string, true>  $tombstones
     *
     * @return list<T>
     */
    private function mergeById(array $existing, array $incoming, callable $id, array $tombstones): array
    {
        $seen = [];
        foreach ($existing as $item) {
            $seen[$id($item)] = true;
        }
        foreach ($incoming as $item) {
            $key = $id($item);
            if (!isset($seen[$key]) && !isset($tombstones[$key])) {
                $existing[] = $item;
                $seen[$key] = true;
            }
        }

        return $existing;
    }

    private function queueKey(Trade $trade): string
    {
        return $trade->broker.'|'.($trade->fifoPool ?: $trade->symbol);
    }

    /**
     * @param array<mixed> $trades
     * @param array<mixed> $positions
     */
    private function applyCompatibilityCountries(array &$trades, array $positions): void
    {
        $countries = [];
        foreach ($positions as $position) {
            if (!is_array($position)) {
                continue;
            }
            $name = mb_strtoupper($this->scalarString($position['name'] ?? null));
            $country = strtoupper($this->scalarString($position['country'] ?? null));
            if ('' !== $name && '' !== $country) {
                $countries[$name] = $country;
            }
        }
        foreach ($trades as &$trade) {
            if (!is_array($trade) || '' !== $this->scalarString($trade['country'] ?? null)) {
                continue;
            }
            $name = mb_strtoupper($this->scalarString($trade['name'] ?? $trade['symbol'] ?? null));
            if (isset($countries[$name])) {
                $trade['country'] = $countries[$name];
            }
        }
        unset($trade);
    }

    /**
     * @param array<mixed> $positions
     *
     * @return list<array<string, string>>
     */
    private function compatibilityRows(array $positions): array
    {
        $result = [];
        foreach ($positions as $row) {
            if (!is_array($row) || '1' === $this->scalarString($row['legacy'] ?? null)) {
                continue;
            }
            $clean = [];
            foreach (['name', 'country', 'currency', 'buy_date', 'buy_amount', 'sell_date', 'sell_amount', 'quantity', 'source'] as $key) {
                $value = $row[$key] ?? '';
                $clean[$key] = is_scalar($value) ? trim((string) $value) : '';
            }
            $result[] = $clean;
        }

        return $result;
    }

    /**
     * @param list<array<mixed>> $groups
     *
     * @return list<string>
     */
    private function submittedTombstones(Request $request, array $groups): array
    {
        $values = $request->request->all('tombstones');
        $ids = [];
        foreach ($values as $value) {
            if (is_scalar($value) && '' !== trim((string) $value)) {
                $ids[(string) $value] = true;
            }
        }
        foreach ($groups as $rows) {
            foreach ($rows as $row) {
                if (is_array($row) && $this->truthy($row['remove'] ?? null) && is_scalar($row['id'] ?? null)) {
                    $ids[(string) $row['id']] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /** @return list<string> */
    private function truncationErrors(string $what, Request $request, string $field, int $received): array
    {
        $expected = $request->request->get($field);
        if (!is_numeric($expected) || $received >= (int) $expected) {
            return [];
        }

        return [sprintf(
            'Formularz dotarł niekompletny: oczekiwano %d %s, a otrzymano %d. Obliczenie przerwano (sprawdź max_input_vars).',
            (int) $expected,
            $what,
            $received,
        )];
    }

    /**
     * @param list<string> $messages
     * @return list<Diagnostic>
     */
    private function diagnosticsFor(array $messages, string $code, string $tab): array
    {
        return array_map(
            static fn (string $message): Diagnostic => Diagnostic::blocking($code, $message, $tab),
            $messages,
        );
    }

    /**
     * @param array<array-key, Diagnostic> $diagnostics
     * @return list<Diagnostic>
     */
    private function uniqueDiagnostics(array $diagnostics): array
    {
        $unique = [];
        foreach ($diagnostics as $diagnostic) {
            $unique[$diagnostic->key()] = $diagnostic;
        }

        return array_values($unique);
    }

    /** @param iterable<Diagnostic> $diagnostics */
    private function hasBlocking(iterable $diagnostics): bool
    {
        foreach ($diagnostics as $diagnostic) {
            if (DiagnosticLevel::Blocking === $diagnostic->level) {
                return true;
            }
        }

        return false;
    }

    /**
     * Countries with no configured treaty rate, but only where that actually
     * changes the declared figure.
     *
     * Under the NSA reading the treaty cap plays no part at all, so the item
     * would be noise; under the conservative reading it means a credit of zero,
     * which really does change the tax due. Filtered to the settled year as
     * well - a dividend from another year is excluded from the report, so
     * reporting it here asked the user to fix something invisible.
     *
     * @param list<Dividend> $dividends
     * @return list<Diagnostic>
     */
    private function treatyDiagnostics(array $dividends, int $year, WorkbenchSettings $settings): array
    {
        if (CreditMethod::Conservative !== $settings->creditMethod) {
            return [];
        }

        $diagnostics = [];
        foreach ($dividends as $dividend) {
            if ($dividend->taxYear() !== $year
                || !CountryCode::isValid($dividend->countryCode)
                || $this->taxRates->isKnownCountry($dividend->countryCode)) {
                continue;
            }
            $diagnostics[] = Diagnostic::review(
                'dividend.treaty_rate_missing',
                sprintf(
                    'Brak skonfigurowanej stawki umownej dla kraju "%s". W wariancie zachowawczym '
                    .'nie przyjęto odliczenia (0 PLN); wariant wg orzecznictwa NSA uwzględnia podatek '
                    .'faktycznie pobrany do limitu 19%%. Zweryfikuj właściwą umowę lub skonsultuj rozliczenie.',
                    $dividend->countryCode,
                ),
                'dividends',
                $dividend->id(),
            );
        }

        return $diagnostics;
    }

    /**
     * Every country the selects may offer, label included.
     *
     * The treaty table is the vocabulary, but it is not the whole world: a
     * listing exchange can name a country with no configured treaty rate, and
     * a row imported before this list existed may already carry one. Such a
     * code has to stay selectable, or the next post silently replaces it with a
     * blank - the option list is what decides whether a value survives a
     * round trip.
     *
     * @param array<int|string, mixed> ...$rowSets
     *
     * @return array<string, string>
     */
    private function countryOptions(array ...$rowSets): array
    {
        $options = $this->taxRates->supportedCountries();

        $extra = [];
        foreach (ExchangeCountry::countries() as $code) {
            $extra[$code] = true;
        }

        foreach ($rowSets as $rows) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                /** @var array<string, mixed> $row */
                $code = mb_strtoupper($this->scalarString($row['country'] ?? null));
                if (CountryCode::isValid($code)) {
                    $extra[$code] = true;
                }
            }
        }

        foreach (array_keys($extra) as $code) {
            if (!isset($options[$code])) {
                $options[$code] = sprintf('%s - poza tabelą umów', $code);
            }
        }

        ksort($options);

        return $options;
    }

    /**
     * Sets one country across every row of one instrument, in both tabs.
     *
     * The group is resolved through {@see CountryReview::groups()} on the posted
     * rows, so the set written is exactly the set the button counted - and rows
     * the user just removed are skipped, because the raw post still carries
     * them while the rendered form no longer does.
     *
     * Blank rows always move. Existing values move only when the panel offered
     * the overwrite variant, which it does for a group whose rows disagree:
     * a paper has one country of income, and after the country started coming
     * from the listing exchange a DEGIRO trade and its dividend can legitimately
     * arrive with two.
     *
     * @param array<mixed> $trades
     * @param array<mixed> $dividends
     *
     * @return array{list<string>, list<Diagnostic>}
     */
    private function applyCountryGroup(Request $request, array &$trades, array &$dividends): array
    {
        $selected = $request->request->get('country_group_apply');
        if (!is_scalar($selected) || '' === trim((string) $selected)) {
            return [[], []];
        }

        $groupId = trim((string) $selected);
        $group = $this->countryReview->groups($trades, $dividends)[$groupId] ?? null;
        if (null === $group) {
            $message = 'Nie można ustalić instrumentu dla zbiorczego ustawienia kraju.';

            return [[$message], [Diagnostic::blocking('country.group_missing', $message, 'attention')]];
        }

        $values = $request->request->all('country_group_value');
        $posted = $this->scalarString($values[$groupId] ?? null);

        if ('' === $posted) {
            // Review, not blocking: a mis-click must not throw away a PIT result
            // that was already on screen.
            return [[], [Diagnostic::review(
                'country.group_value_missing',
                sprintf('Wybierz kraj przed użyciem przycisku zbiorczego dla %s.', $group->label),
                'attention',
                null,
                $groupId,
            )]];
        }

        try {
            $country = CountryCode::normalizeRequired($posted, $group->label);
        } catch (InvalidRecordException $e) {
            $message = 'Zbiorcze ustawienie kraju: '.$e->getMessage();

            return [[$message], [Diagnostic::blocking('country.group_invalid', $message, 'attention', null, $groupId)]];
        }

        $overwrite = '' !== $this->scalarString($request->request->all('country_group_overwrite')[$groupId] ?? null);

        // One group belongs to one tab, so exactly one of the two row sets moves.
        // Writing both would put a dividend's payer-residence country onto the
        // trades, or the place of disposal onto the dividend.
        if (CountryScope::Trades === $group->scope) {
            $this->writeCountry($trades, $group->indexes, $country, $overwrite);
        } else {
            $this->writeCountry($dividends, $group->indexes, $country, $overwrite);
        }

        return [[], []];
    }

    /**
     * @param array<mixed> $rows
     * @param list<int>    $indexes
     */
    private function writeCountry(array &$rows, array $indexes, string $country, bool $overwrite): void
    {
        foreach ($indexes as $index) {
            $row = $rows[$index] ?? null;
            if (!is_array($row)) {
                continue;
            }

            if (!$overwrite && '' !== $this->scalarString($row['country'] ?? null)) {
                continue;
            }

            $row['country'] = $country;
            $rows[$index] = $row;
        }
    }

    /**
     * Server-side fallback for the per-pool country action. The posted group
     * identifier is deliberately ignored: broker and FIFO pool are recomputed
     * from the selected row, and only blank countries in that exact group move.
     *
     * @param array<mixed> $trades
     * @return array{list<string>, list<Diagnostic>}
     */
    private function applyBulkCountry(Request $request, array &$trades): array
    {
        $selected = $request->request->get('bulk_country');
        if (!is_scalar($selected) || '' === trim((string) $selected)) {
            return [[], []];
        }

        $key = (string) $selected;
        $row = $trades[$key] ?? null;
        if (!is_array($row)) {
            $message = 'Nie można ustalić transakcji źródłowej dla zbiorczego ustawienia kraju.';

            return [[$message], [Diagnostic::blocking('country.bulk_row_missing', $message, 'transactions')]];
        }

        $name = $this->scalarString($row['name'] ?? $row['symbol'] ?? null) ?: 'transakcja';

        if ('' === $this->scalarString($row['country'] ?? null)) {
            // Review, not blocking: pressing the button before picking a country
            // is a mis-click, and it must not throw away a PIT result that was
            // already on screen.
            return [[], [Diagnostic::review(
                'country.bulk_value_missing',
                sprintf('Wybierz kraj przed użyciem przycisku zbiorczego dla %s.', $name),
                'transactions',
                self::formRowId($row),
            )]];
        }

        try {
            $country = CountryCode::normalizeRequired($this->scalarString($row['country'] ?? null), $name);
        } catch (InvalidRecordException $e) {
            $message = 'Zbiorcze ustawienie kraju: '.$e->getMessage();

            return [[$message], [Diagnostic::blocking(
                'country.bulk_invalid',
                $message,
                'transactions',
                self::formRowId($row),
            )]];
        }

        $queue = $this->formQueueKey($row);
        foreach ($trades as &$candidate) {
            if (!is_array($candidate)
                || $this->formQueueKey($candidate) !== $queue
                || '' !== $this->scalarString($candidate['country'] ?? null)) {
                continue;
            }
            $candidate['country'] = $country;
        }
        unset($candidate);

        return [[], []];
    }

    /** @param array<mixed> $row */
    private function formQueueKey(array $row): string
    {
        $broker = mb_strtoupper($this->scalarString($row['broker'] ?? null));
        $pool = $this->scalarString($row['pool'] ?? null);
        if ('' === $pool) {
            $pool = $this->scalarString($row['symbol'] ?? null);
        }

        return $broker.'|'.mb_strtoupper($pool);
    }

    /** @param array<string, mixed> $row */
    private static function formRowId(array $row): ?string
    {
        $value = $row['id'] ?? null;

        return is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    private function assertCsrfToken(Request $request): void
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.');
        }
    }

    /** @return list<UploadedFile> */
    private function uploadedFiles(Request $request): array
    {
        $files = $request->files->get('files');
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        return is_array($files)
            ? array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile))
            : [];
    }

    private function truthy(mixed $value): bool
    {
        return is_scalar($value) && in_array((string) $value, ['1', 'on', 'true'], true);
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

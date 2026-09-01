<?php

declare(strict_types=1);

namespace App\Controller;

use App\Import\CsvImportService;
use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Report\CsvReportWriter;
use App\Report\TaxReport;
use App\Report\TaxReportBuilder;
use App\Tax\TaxRates;
use App\Tax\TaxYearFilter;
use App\Web\RowFormMapper;
use App\Web\TaxYearProvider;
use App\Web\Upload\UploadedCsvReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The whole calculator flow.
 *
 * Nothing the user uploads is ever persisted: an import is turned into form
 * fields that travel back with the next POST, so between requests the data
 * exists only in the user's own browser. The session holds a CSRF token and
 * nothing else.
 */
final class CalculatorController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'kalkulator';

    public function __construct(
        private readonly UploadedCsvReader $uploadedCsvReader,
        private readonly CsvImportService $importService,
        private readonly RowFormMapper $rowFormMapper,
        private readonly TaxReportBuilder $reportBuilder,
        private readonly CsvReportWriter $csvReportWriter,
        private readonly TaxYearProvider $taxYearProvider,
        private readonly TaxYearFilter $taxYearFilter,
        private readonly TaxRates $taxRates,
        private readonly int $maxFiles,
        private readonly int $maxBytes,
    ) {
    }

    #[Route('/kalkulator', name: 'app_calculator', methods: ['GET'])]
    public function index(#[MapQueryParameter] ?int $rok = null): Response
    {
        return $this->render('calculator/index.html.twig', [
            'tax_years' => $this->taxYearProvider->years(),
            'selected_year' => $this->taxYearProvider->normalize($rok),
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'example_files' => ExampleFileController::FILES,
            'max_files' => $this->maxFiles,
            'max_megabytes' => round($this->maxBytes / 1024 / 1024, 1),
        ]);
    }

    #[Route('/kalkulator/import', name: 'app_calculator_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $this->assertCsrfToken($request);

        $year = $this->taxYearProvider->normalize($request->request->get('tax_year'));

        $uploads = $this->uploadedFiles($request);
        $read = $this->uploadedCsvReader->read($uploads);

        if ([] !== $read->rejections) {
            return $this->renderReview([], [], $year, $read->rejectionMessages(), [], []);
        }

        $result = $this->importService->import($read->sources);

        $errors = $result->errors();

        if ([] === $uploads) {
            $errors[] = 'Nie wybrano żadnego pliku. Wskaż co najmniej jeden plik CSV.';
        } elseif ($result->isEmpty() && [] === $errors) {
            $errors[] = 'W przesłanych plikach nie znaleziono żadnych transakcji ani dywidend do rozliczenia.';
        }

        return $this->renderReview($result->positions, $result->dividends, $year, $errors, $result->warnings(), $result->infos());
    }

    #[Route('/kalkulator/wynik', name: 'app_calculator_result', methods: ['POST'])]
    public function result(Request $request): Response
    {
        $this->assertCsrfToken($request);

        [$positions, $dividends, $year, $errors, $positionRows, $dividendRows] = $this->readSubmission($request);

        if ([] !== $errors) {
            return $this->renderReviewRows($positionRows, $dividendRows, $year, $errors, [], []);
        }

        $report = $this->reportBuilder->build($positions, $dividends, $year);

        if ([] !== $report->errors) {
            return $this->renderReview($positions, $dividends, $year, $report->errors, $report->warnings, []);
        }

        return $this->render('calculator/result.html.twig', $this->reportContext($report, $positions, $dividends));
    }

    #[Route('/kalkulator/raport.csv', name: 'app_calculator_report_csv', methods: ['POST'])]
    public function csvReport(Request $request): Response
    {
        $this->assertCsrfToken($request);

        [$positions, $dividends, $year, $errors, $positionRows, $dividendRows] = $this->readSubmission($request);

        if ([] !== $errors) {
            return $this->renderReviewRows($positionRows, $dividendRows, $year, $errors, [], []);
        }

        $report = $this->reportBuilder->build($positions, $dividends, $year);

        if ([] !== $report->errors) {
            return $this->renderReview($positions, $dividends, $year, $report->errors, $report->warnings, []);
        }

        // Generated on the fly from the submitted rows and streamed straight
        // back - the file never exists on the server.
        $response = new Response($this->csvReportWriter->write($report));
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

        [$positions, $dividends, $year, $errors, $positionRows, $dividendRows] = $this->readSubmission($request);

        if ([] !== $errors) {
            return $this->renderReviewRows($positionRows, $dividendRows, $year, $errors, [], []);
        }

        $report = $this->reportBuilder->build($positions, $dividends, $year);

        if ([] !== $report->errors) {
            return $this->renderReview($positions, $dividends, $year, $report->errors, $report->warnings, []);
        }

        return $this->render('report/print.html.twig', $this->reportContext($report, $positions, $dividends));
    }

    /**
     * @return array{list<ClosedPosition>, list<Dividend>, int, list<string>, list<array<string, string>>, list<array<string, string>>}
     */
    private function readSubmission(Request $request): array
    {
        $year = $this->taxYearProvider->normalize($request->request->get('tax_year'));

        $rawPositions = $request->request->all('positions');
        $rawDividends = $request->request->all('dividends');

        $truncation = [
            ...$this->truncationErrors('pozycji', $request, 'expected_positions', count($rawPositions)),
            ...$this->truncationErrors('dywidend', $request, 'expected_dividends', count($rawDividends)),
        ];

        $positions = $this->rowFormMapper->mapPositions($rawPositions);
        $dividends = $this->rowFormMapper->mapDividends($rawDividends);

        return [
            $positions->positions,
            $dividends->dividends,
            $year,
            [...$truncation, ...$positions->errors, ...$dividends->errors],
            // The raw rows, valid or not, so a rejected submission can be shown
            // back to the user for correction instead of silently vanishing.
            $positions->rows,
            $dividends->rows,
        ];
    }

    /**
     * Detects a POST body that arrived incomplete.
     *
     * PHP drops input variables silently once `max_input_vars` is reached, so a
     * large review form can arrive with its tail missing and would otherwise be
     * settled as if those rows never existed. The page states how many rows it
     * sent; anything fewer is refused rather than taxed.
     *
     * @return list<string>
     */
    private function truncationErrors(string $what, Request $request, string $field, int $received): array
    {
        $expected = $request->request->get($field);
        if (!is_numeric($expected)) {
            return [];
        }

        $expected = (int) $expected;
        if ($received >= $expected) {
            return [];
        }

        return [sprintf(
            'Formularz dotarł niekompletny: oczekiwano %d %s, a otrzymano %d. '
            .'Najczęstsza przyczyna to zbyt niski limit "max_input_vars" w konfiguracji PHP (obecnie %s). '
            .'Zwiększ ten limit albo podziel dane na mniejsze części - obliczenie zostało przerwane, '
            .'żeby nie podać zaniżonego podatku.',
            $expected,
            $what,
            $received,
            self::describeIniLimit('max_input_vars'),
        )];
    }

    private static function describeIniLimit(string $directive): string
    {
        $value = ini_get($directive);

        return false === $value || '' === $value ? 'nieznany' : $value;
    }

    /**
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     * @param list<string>         $errors
     * @param list<string>         $warnings
     * @param list<string>         $infos
     */
    private function renderReview(
        array $positions,
        array $dividends,
        int $year,
        array $errors,
        array $warnings,
        array $infos,
    ): Response {
        return $this->renderReviewRows(
            array_map($this->rowFormMapper->positionToForm(...), $positions),
            array_map($this->rowFormMapper->dividendToForm(...), $dividends),
            $year,
            $errors,
            $warnings,
            $infos,
            $this->taxYearFilter->availableYears($positions, $dividends),
            $this->taxYearFilter->excludedPositionCount($positions, $year),
            $this->taxYearFilter->excludedDividendCount($dividends, $year),
        );
    }

    /**
     * Renders the review screen straight from form rows.
     *
     * A rejected submission is re-displayed from the user's own input, so a row
     * that failed validation - a blank country, a tampered amount - comes back
     * editable instead of disappearing.
     *
     * @param list<array<string, string>> $positionRows
     * @param list<array<string, string>> $dividendRows
     * @param list<string>                $errors
     * @param list<string>                $warnings
     * @param list<string>                $infos
     * @param list<int>                   $availableYears
     */
    private function renderReviewRows(
        array $positionRows,
        array $dividendRows,
        int $year,
        array $errors,
        array $warnings,
        array $infos,
        array $availableYears = [],
        int $excludedPositions = 0,
        int $excludedDividends = 0,
    ): Response {
        return $this->render('calculator/review.html.twig', [
            'tax_years' => $this->taxYearProvider->years(),
            'selected_year' => $year,
            'available_years' => $availableYears,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'countries' => $this->taxRates->supportedCountries(),
            'position_rows' => $positionRows,
            'dividend_rows' => $dividendRows,
            'errors' => $errors,
            'warnings' => $warnings,
            'infos' => $infos,
            'excluded_positions' => $excludedPositions,
            'excluded_dividends' => $excludedDividends,
            'expected_positions' => count($positionRows),
            'expected_dividends' => count($dividendRows),
        ]);
    }

    /**
     * @param list<ClosedPosition> $positions
     * @param list<Dividend>       $dividends
     *
     * @return array<string, mixed>
     */
    private function reportContext(TaxReport $report, array $positions, array $dividends): array
    {
        return [
            'report' => $report,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'position_rows' => array_map($this->rowFormMapper->positionToForm(...), $positions),
            'dividend_rows' => array_map($this->rowFormMapper->dividendToForm(...), $dividends),
            'expected_positions' => count($positions),
            'expected_dividends' => count($dividends),
            'disclaimer' => CsvReportWriter::DISCLAIMER,
        ];
    }

    private function assertCsrfToken(Request $request): void
    {
        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $token)) {
            // A plain HTTP exception rather than a security one: this app has no
            // firewall, so nothing would translate an AccessDeniedException.
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF. Odśwież stronę i spróbuj ponownie.');
        }
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedFiles(Request $request): array
    {
        $files = $request->files->get('files');

        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static fn (mixed $f): bool => $f instanceof UploadedFile));
    }
}

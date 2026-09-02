<?php

declare(strict_types=1);

namespace App\Command;

use App\Report\CsvReportWriter;
use App\Tax\CreditMethod;
use App\Report\TaxReport;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a {@see TaxReport} to the console.
 *
 * Values are labelled semantically ("Przychód", "Koszty uzyskania przychodu")
 * rather than by PIT field number, because those numbers move between form
 * versions and printing a stale one is worse than printing none.
 */
final class ReportRenderer
{
    public function renderStock(SymfonyStyle $io, TaxReport $report): void
    {
        $stock = $report->stock;

        $io->section(sprintf('Akcje i ETF-y — PIT-38, część C (rok %d)', $report->taxYear));

        if ([] === $stock->positions) {
            $io->text('Brak pozycji zamkniętych w tym roku podatkowym.');
        } else {
            $io->table(
                ['Lp.', 'Instrument', 'Kraj', 'Waluta', 'Zakup', 'Kurs', 'Koszt PLN', 'Sprzedaż', 'Kurs', 'Przychód PLN', 'Dochód PLN'],
                array_map(
                    static fn (int $index, $item): array => [
                        $index + 1,
                        $item->position->name,
                        $item->position->countryCode ?: '—',
                        $item->position->currency,
                        $item->position->buyDate->format('Y-m-d'),
                        (string) $item->cost->rate,
                        (string) $item->totalCost()->value(),
                        $item->position->sellDate->format('Y-m-d'),
                        (string) $item->revenue->rate,
                        (string) $item->revenue->pln->value(),
                        (string) $item->income->value(),
                    ],
                    array_keys($stock->positions),
                    $stock->positions,
                ),
            );
        }

        $io->definitionList(
            ['Przychód' => $stock->totalRevenue->value().' PLN'],
            ['Koszty uzyskania przychodu' => $stock->totalCost->value().' PLN'],
            ['w tym koszty zbycia' => $stock->disposalCost->value().' PLN'],
            [($stock->isLoss() ? 'Strata' : 'Dochód') => ($stock->isLoss() ? $stock->loss->value() : $stock->income->value()).' PLN'],
            ['Podatek 19%' => $stock->tax->value().' PLN'],
        );

        if ([] !== $stock->countries) {
            $io->section('PIT/ZG — dochód z akcji według kraju');
            $io->table(
                ['Kraj', 'Przychód PLN', 'Koszty PLN', 'Dochód PLN'],
                array_map(
                    static fn ($country): array => [
                        $country->countryCode ?: 'nieokreślony',
                        (string) $country->revenue->value(),
                        (string) $country->cost->value(),
                        (string) $country->income->value(),
                    ],
                    $stock->countries,
                ),
            );
        }
    }

    public function renderDividends(SymfonyStyle $io, TaxReport $report): void
    {
        $dividends = $report->dividends;

        $io->section(sprintf('Dywidendy — PIT-38, część G (rok %d)', $report->taxYear));

        if ([] === $dividends->dividends) {
            $io->text('Brak dywidend w tym roku podatkowym.');
        } else {
            $io->table(
                [
                    'Lp.', 'Instrument', 'Kraj', 'Data', 'Brutto PLN', 'Kurs', 'Pobrany PLN', 'Podatek PL PLN',
                    'Odlicz. zach.', 'Do zapł. zach.', 'Odlicz. NSA', 'Do zapł. NSA',
                ],
                array_map(
                    static fn (int $index, $item): array => [
                        $index + 1,
                        $item->dividend->name,
                        $item->dividend->countryCode ?: '—',
                        $item->dividend->date->format('Y-m-d'),
                        (string) $item->gross->pln->value(),
                        (string) $item->gross->rate,
                        (string) $item->withheldTax->pln->value(),
                        (string) $item->polishTax->toScale(2)->value(),
                        (string) $item->conservative->creditableTax->toScale(2)->value(),
                        (string) $item->conservative->taxDue->toScale(2)->value(),
                        (string) $item->nsa->creditableTax->toScale(2)->value(),
                        (string) $item->nsa->taxDue->toScale(2)->value(),
                    ],
                    array_keys($dividends->dividends),
                    $dividends->dividends,
                ),
            );
        }

        $io->definitionList(
            ['Przychód brutto' => $dividends->totalGross->value().' PLN'],
            ['Podatek polski 19%' => $dividends->totalPolishTax->value().' PLN'],
            ['Podatek pobrany za granicą' => $dividends->totalWithheldTax->value().' PLN'],
        );

        $io->section('Dwa warianty odliczenia podatku zagranicznego');
        $io->table(
            ['Wariant', 'Do odliczenia PLN', 'Dywidendy do zapłaty PLN'],
            [
                [
                    CreditMethod::Conservative->label(),
                    (string) $dividends->conservative->creditableTax->value(),
                    (string) $dividends->conservative->taxDue->value(),
                ],
                [
                    CreditMethod::Nsa->label(),
                    (string) $dividends->nsa->creditableTax->value(),
                    (string) $dividends->nsa->taxDue->value(),
                ],
            ],
        );

        if ([] !== $dividends->countries) {
            $io->section('Dywidendy według kraju (art. 30a - bez PIT/ZG)');
            $io->table(
                [
                    'Kraj', 'Brutto PLN', 'Pobrany PLN', 'Podatek PL PLN',
                    'Odlicz. zach.', 'Do zapł. zach.', 'Odlicz. NSA', 'Do zapł. NSA',
                ],
                array_map(
                    static fn ($country): array => [
                        $country->countryCode ?: 'nieokreślony',
                        (string) $country->gross->value(),
                        (string) $country->withheldTax->value(),
                        (string) $country->polishTax->value(),
                        (string) $country->conservative->creditableTax->value(),
                        (string) $country->conservative->taxDue->value(),
                        (string) $country->nsa->creditableTax->value(),
                        (string) $country->nsa->taxDue->value(),
                    ],
                    $dividends->countries,
                ),
            );
        }
    }

    public function renderMessages(SymfonyStyle $io, TaxReport $report): void
    {
        foreach ($report->errors as $error) {
            $io->warning($error);
        }

        foreach ($report->warnings as $warning) {
            $io->note($warning);
        }

        if ($report->hasExclusions()) {
            $io->note(sprintf(
                'Pominięto %d pozycji i %d dywidend spoza roku %d.',
                $report->excludedPositions,
                $report->excludedDividends,
                $report->taxYear,
            ));
        }
    }

    /**
     * Prints both totals side by side. There is deliberately no single
     * "the tax" line: which one applies is the disputed question.
     */
    public function renderTotals(SymfonyStyle $io, TaxReport $report): void
    {
        $io->section('Razem — dwa warianty');
        $io->table(
            ['Wariant', 'Podatek łącznie PLN', 'W pełnych złotych'],
            [
                [
                    CreditMethod::Conservative->label(),
                    (string) $report->totalTaxConservative->value(),
                    (string) $report->totalTaxConservativeRounded->value(),
                ],
                [
                    CreditMethod::Nsa->label(),
                    (string) $report->totalTaxNsa->value(),
                    (string) $report->totalTaxNsaRounded->value(),
                ],
            ],
        );

        if ($report->scenariosDiffer()) {
            $io->warning(sprintf(
                'Warianty różnią się o %s PLN. Wybór należy do podatnika - rozważ interpretację '
                .'indywidualną albo konsultację z doradcą podatkowym.',
                (string) $report->scenarioDifference()->value(),
            ));
        }

        $io->text(CreditMethod::Conservative->label().': '.CreditMethod::Conservative->description());
        $io->newLine();
        $io->text(CreditMethod::Nsa->label().': '.CreditMethod::Nsa->description());
    }

    public function renderDisclaimer(SymfonyStyle $io): void
    {
        $io->block(CsvReportWriter::DISCLAIMER, 'ZASTRZEŻENIE', 'comment', ' ! ');
    }
}

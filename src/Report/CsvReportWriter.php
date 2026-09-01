<?php

declare(strict_types=1);

namespace App\Report;

use App\Tax\CreditMethod;
use App\Tax\Result\CalculatedDividend;
use App\Tax\Result\CalculatedPosition;
use League\Csv\Writer;

/**
 * Renders a {@see TaxReport} as a self-contained CSV.
 *
 * Built entirely in memory from data the user just submitted; nothing is
 * written to disk and nothing is kept after the response is sent.
 */
final class CsvReportWriter
{
    private const int PLN_SCALE = 2;

    public const string SCENARIO_NOTE =
        'Wysokosc odliczenia zagranicznego podatku u zrodla jest sporna. Ponizej podano oba warianty; '
        .'wybor nalezy do podatnika. Przy istotnej roznicy rozwaz wystapienie o interpretacje indywidualna '
        .'albo konsultacje z doradca podatkowym.';

    public const string DISCLAIMER =
        'Wyliczenie ma charakter pomocniczy i nie stanowi porady podatkowej. '
        .'Zweryfikuj wartości z aktualnymi przepisami i formularzami przed złożeniem zeznania.';

    public function write(TaxReport $report): string
    {
        $writer = Writer::fromString();

        $this->writeSummary($writer, $report);
        $this->writeCountries($writer, $report);
        $this->writePositions($writer, $report);
        $this->writeDividends($writer, $report);
        $this->writeMessages($writer, $report);

        // The BOM makes Excel open the file as UTF-8 so Polish characters and
        // instrument names survive a round trip.
        return "\u{FEFF}".$writer->toString();
    }

    public function filename(TaxReport $report): string
    {
        return sprintf('pit-38-%d-raport.csv', $report->taxYear);
    }

    private function writeSummary(Writer $writer, TaxReport $report): void
    {
        $this->section($writer, 'PODSUMOWANIE');
        $this->row($writer, ['Rok podatkowy', (string) $report->taxYear]);
        $this->row($writer, ['Zastrzeżenie', self::DISCLAIMER]);
        $this->row($writer, []);

        $this->section($writer, 'AKCJE I ETF (PIT-38 czesc C)');
        $this->row($writer, ['Pozycja', 'Kwota (PLN)']);
        $this->row($writer, ['Przychod', (string) $report->stock->totalRevenue->value()]);
        $this->row($writer, ['Koszty uzyskania przychodu', (string) $report->stock->totalCost->value()]);
        $this->row($writer, ['Dochod', (string) $report->stock->income->value()]);
        $this->row($writer, ['Strata', (string) $report->stock->loss->value()]);
        $this->row($writer, ['Podatek 19%', (string) $report->stock->tax->value()]);
        $this->row($writer, []);

        $this->section($writer, 'DYWIDENDY (PIT-38 czesc G)');
        $this->row($writer, ['Pozycja', 'Kwota (PLN)']);
        $this->row($writer, ['Przychod brutto', (string) $report->dividends->totalGross->value()]);
        $this->row($writer, ['Podatek polski 19%', (string) $report->dividends->totalPolishTax->value()]);
        $this->row($writer, ['Podatek pobrany za granica', (string) $report->dividends->totalWithheldTax->value()]);
        $this->row($writer, []);

        $this->section($writer, 'DWA WARIANTY ODLICZENIA PODATKU ZAGRANICZNEGO');
        $this->row($writer, ['Uwaga', self::SCENARIO_NOTE]);
        $this->row($writer, ['Wariant', 'Do odliczenia (PLN)', 'Dywidendy - do zaplaty (PLN)', 'Podatek lacznie (PLN)', 'Lacznie w pelnych zlotych']);
        $this->row($writer, [
            CreditMethod::Conservative->label(),
            (string) $report->dividends->conservative->creditableTax->value(),
            (string) $report->dividends->conservative->taxDue->value(),
            (string) $report->totalTaxConservative->value(),
            (string) $report->totalTaxConservativeRounded->value(),
        ]);
        $this->row($writer, [
            CreditMethod::Nsa->label(),
            (string) $report->dividends->nsa->creditableTax->value(),
            (string) $report->dividends->nsa->taxDue->value(),
            (string) $report->totalTaxNsa->value(),
            (string) $report->totalTaxNsaRounded->value(),
        ]);
        $this->row($writer, ['Roznica miedzy wariantami', (string) $report->scenarioDifference()->value()]);
        $this->row($writer, [CreditMethod::Conservative->label(), CreditMethod::Conservative->description()]);
        $this->row($writer, [CreditMethod::Nsa->label(), CreditMethod::Nsa->description()]);
        $this->row($writer, []);
    }

    private function writeCountries(Writer $writer, TaxReport $report): void
    {
        if ([] !== $report->stock->countries) {
            $this->section($writer, 'AKCJE WEDLUG KRAJU (PIT/ZG)');
            $this->row($writer, ['Kraj', 'Nazwa', 'Przychod (PLN)', 'Koszty (PLN)', 'Dochod (PLN)']);
            foreach ($report->stock->countries as $country) {
                $this->row($writer, [
                    $country->countryCode,
                    $country->countryName ?? '',
                    (string) $country->revenue->value(),
                    (string) $country->cost->value(),
                    (string) $country->income->value(),
                ]);
            }
            $this->row($writer, []);
        }

        if ([] !== $report->dividends->countries) {
            $this->section($writer, 'DYWIDENDY WEDLUG KRAJU (PIT/ZG)');
            $this->row($writer, [
                'Kraj', 'Nazwa', 'Przychod brutto (PLN)', 'Podatek pobrany (PLN)', 'Podatek polski (PLN)',
                'Do odliczenia - zachowawczy (PLN)', 'Do zaplaty - zachowawczy (PLN)',
                'Do odliczenia - wg NSA (PLN)', 'Do zaplaty - wg NSA (PLN)',
            ]);
            foreach ($report->dividends->countries as $country) {
                $this->row($writer, [
                    $country->countryCode,
                    $country->countryName ?? '',
                    (string) $country->gross->value(),
                    (string) $country->withheldTax->value(),
                    (string) $country->polishTax->value(),
                    (string) $country->conservative->creditableTax->value(),
                    (string) $country->conservative->taxDue->value(),
                    (string) $country->nsa->creditableTax->value(),
                    (string) $country->nsa->taxDue->value(),
                ]);
            }
            $this->row($writer, []);
        }
    }

    private function writePositions(Writer $writer, TaxReport $report): void
    {
        if ([] === $report->stock->positions) {
            return;
        }

        $this->section($writer, 'SZCZEGOLY - AKCJE I ETF');
        $this->row($writer, [
            'Lp.', 'Instrument', 'Kraj', 'Waluta', 'Liczba',
            'Data zakupu', 'Kwota zakupu', 'Kurs NBP zakupu', 'Data kursu zakupu', 'Koszt (PLN)',
            'Data sprzedazy', 'Kwota sprzedazy', 'Kurs NBP sprzedazy', 'Data kursu sprzedazy', 'Przychod (PLN)',
            'Dochod (PLN)', 'Zrodlo',
        ]);

        foreach ($report->stock->positions as $index => $position) {
            $this->row($writer, $this->positionRow($index + 1, $position));
        }

        $this->row($writer, []);
    }

    /**
     * @return list<string>
     */
    private function positionRow(int $number, CalculatedPosition $position): array
    {
        return [
            (string) $number,
            $position->position->name,
            $position->position->countryCode,
            $position->position->currency,
            null === $position->position->quantity ? '' : (string) $position->position->quantity,
            $position->position->buyDate->format('Y-m-d'),
            (string) $position->cost->original->value(),
            (string) $position->cost->rate,
            $position->cost->rateDate?->format('Y-m-d') ?? '',
            (string) $position->cost->pln->value(),
            $position->position->sellDate->format('Y-m-d'),
            (string) $position->revenue->original->value(),
            (string) $position->revenue->rate,
            $position->revenue->rateDate?->format('Y-m-d') ?? '',
            (string) $position->revenue->pln->value(),
            (string) $position->income->value(),
            $position->position->source,
        ];
    }

    private function writeDividends(Writer $writer, TaxReport $report): void
    {
        if ([] === $report->dividends->dividends) {
            return;
        }

        $this->section($writer, 'SZCZEGOLY - DYWIDENDY');
        $this->row($writer, [
            'Lp.', 'Instrument', 'Kraj', 'Waluta', 'Data wyplaty',
            'Brutto', 'Kurs NBP', 'Data kursu', 'Brutto (PLN)',
            'Podatek pobrany', 'Podatek pobrany (PLN)', 'Stawka umowna (%)', 'Podatek polski (PLN)',
            'Do odliczenia - zachowawczy (PLN)', 'Do zaplaty - zachowawczy (PLN)',
            'Do odliczenia - wg NSA (PLN)', 'Do zaplaty - wg NSA (PLN)', 'Zrodlo', 'Uwagi',
        ]);

        foreach ($report->dividends->dividends as $index => $dividend) {
            $this->row($writer, $this->dividendRow($index + 1, $dividend));
        }

        $this->row($writer, []);
    }

    /**
     * @return list<string>
     */
    private function dividendRow(int $number, CalculatedDividend $dividend): array
    {
        return [
            (string) $number,
            $dividend->dividend->name,
            $dividend->dividend->countryCode,
            $dividend->dividend->currency,
            $dividend->dividend->date->format('Y-m-d'),
            (string) $dividend->gross->original->value(),
            (string) $dividend->gross->rate,
            $dividend->gross->rateDate?->format('Y-m-d') ?? '',
            (string) $dividend->gross->pln->value(),
            (string) $dividend->withheldTax->original->value(),
            (string) $dividend->withheldTax->pln->value(),
            null === $dividend->treatyPercent ? '' : (string) $dividend->treatyPercent,
            (string) $dividend->polishTax->toScale(self::PLN_SCALE)->value(),
            (string) $dividend->conservative->creditableTax->toScale(self::PLN_SCALE)->value(),
            (string) $dividend->conservative->taxDue->toScale(self::PLN_SCALE)->value(),
            (string) $dividend->nsa->creditableTax->toScale(self::PLN_SCALE)->value(),
            (string) $dividend->nsa->taxDue->toScale(self::PLN_SCALE)->value(),
            $dividend->dividend->source,
            $dividend->warning ?? '',
        ];
    }

    private function writeMessages(Writer $writer, TaxReport $report): void
    {
        $messages = [...$report->errors, ...$report->warnings];
        if ([] === $messages) {
            return;
        }

        $this->section($writer, 'UWAGI');
        foreach ($messages as $message) {
            $this->row($writer, [$message]);
        }
    }

    private function section(Writer $writer, string $title): void
    {
        $this->row($writer, [$title]);
    }

    /**
     * @param list<string> $cells
     */
    private function row(Writer $writer, array $cells): void
    {
        $writer->insertOne(array_map(CsvCell::safe(...), $cells));
    }
}

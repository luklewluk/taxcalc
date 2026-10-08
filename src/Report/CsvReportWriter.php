<?php

declare(strict_types=1);

namespace App\Report;

use App\Tax\CreditMethod;
use App\Tax\Result\CalculatedDividend;
use App\Tax\Result\CalculatedPosition;
use League\Csv\Writer;
use App\Fifo\LotAssignments;
use App\Fifo\LotMethod;
use App\Fifo\Trade;
use App\Settlement\SettlementCycle;
use App\Model\AccountFee;
use App\Model\Dividend;

/**
 * Renders a {@see TaxReport} as a self-contained CSV.
 *
 * Built entirely in memory from data the user just submitted; nothing is
 * written to disk and nothing is kept after the response is sent.
 */
final class CsvReportWriter
{
    private const int PLN_SCALE = 2;

    public const string CREDIT_NOTE =
        'Wysokosc odliczenia zagranicznego podatku u zrodla jest sporna. Raport podaje wariant wybrany '
        .'w ustawieniach kalkulatora. W razie watpliwosci rozwaz wystapienie o interpretacje indywidualna '
        .'albo konsultacje z doradca podatkowym.';

    public const string SPECIFIC_LOT_NOTE =
        'Czesc pozycji ma koszt partii wskazanej zamiast najstarszej (FIFO). Kolejnosc FIFO z art. 30b ust. 7 '
        .'ustawy o PIT stosuje sie, gdy nie da sie ustalic, ktore papiery zbyto; jesli broker pozwolil wskazac '
        .'partie, mozna przyjac jej koszt - interpretacja indywidualna Dyrektora KIS z 13.02.2026, '
        .'0112-KDIL2-1.4011.929.2025.1.TR. Interpretacja chroni tylko wnioskodawce; zachowaj potwierdzenie '
        .'wyboru partii od brokera.';

    public const string DISCLAIMER =
        'Wyliczenie ma charakter pomocniczy i nie stanowi porady podatkowej. '
        .'Zweryfikuj wartości z aktualnymi przepisami i formularzami przed złożeniem zeznania.';

    /**
     * @param list<Trade>      $trades
     * @param list<AccountFee> $fees
     * @param list<Dividend>   $dividends
     */
    public function write(
        TaxReport $report,
        array $trades = [],
        array $fees = [],
        array $dividends = [],
        CreditMethod $chosen = CreditMethod::Conservative,
        LotAssignments $assignments = new LotAssignments(),
        SettlementCycle $cycle = SettlementCycle::TradeDate,
    ): string {
        $writer = Writer::fromString();

        // Only the reading chosen in the workbench settings. The file leaves the
        // page, so its summary states once which reading that is; the columns
        // are not labelled again.
        $this->writeSummary($writer, $report, $chosen, $cycle);
        $this->writeCountries($writer, $report, $chosen);
        $this->writePositions($writer, $report);
        $this->writeDividends($writer, $report, $chosen);
        $this->writeRawTrades($writer, $trades);
        $this->writeLotAssignments($writer, $assignments, $trades);
        $this->writeCurrentDividends($writer, $dividends);
        $this->writeFees($writer, $fees, $report);
        $this->writeMessages($writer, $report);

        // The BOM makes Excel open the file as UTF-8 so Polish characters and
        // instrument names survive a round trip.
        return "\u{FEFF}".$writer->toString();
    }

    public function filename(TaxReport $report): string
    {
        return sprintf('pit-38-%d-raport.csv', $report->taxYear);
    }

    private function writeSummary(Writer $writer, TaxReport $report, CreditMethod $chosen, SettlementCycle $cycle): void
    {
        $this->section($writer, 'PODSUMOWANIE');
        $this->row($writer, ['Rok podatkowy', (string) $report->taxYear]);
        // Stated once, like the credit reading: it moves rates and tax years.
        $this->row($writer, ['Cykl rozliczenia', $cycle->label()]);
        $this->row($writer, ['Zastrzeżenie', self::DISCLAIMER]);
        $this->row($writer, []);

        $this->section($writer, $report->stock->hasOptions() ? 'AKCJE, ETF I OPCJE (PIT-38 czesc C)' : 'AKCJE I ETF (PIT-38 czesc C)');
        $this->row($writer, ['Pozycja', 'Kwota (PLN)']);
        $this->row($writer, ['Przychod', (string) $report->stock->totalRevenue->value()]);
        $this->row($writer, ['Koszty uzyskania przychodu', (string) $report->stock->totalCost->value()]);
        $this->row($writer, ['Koszty zbycia', (string) $report->stock->disposalCost->value()]);
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

        $scenario = $report->dividends->scenarioFor($chosen);
        $this->section($writer, 'WARIANT ODLICZENIA PODATKU ZAGRANICZNEGO');
        $this->row($writer, ['Uwaga', self::CREDIT_NOTE]);
        $this->row($writer, ['Wariant', $chosen->label()]);
        $this->row($writer, ['Opis', $chosen->description()]);
        $this->row($writer, ['Pozycja', 'Kwota (PLN)']);
        $this->row($writer, ['Do odliczenia', (string) $scenario->creditableTax->value()]);
        $this->row($writer, ['Dywidendy - do zaplaty', (string) $scenario->taxDue->value()]);
        $this->row($writer, ['Podatek lacznie', (string) $report->totalTaxFor($chosen)->value()]);
        $this->row($writer, ['Podatek lacznie w pelnych zlotych', (string) $report->totalTaxRoundedFor($chosen)->value()]);
        $this->row($writer, []);
    }

    private function writeCountries(Writer $writer, TaxReport $report, CreditMethod $chosen): void
    {
        if ([] !== $report->stock->pitZgCountries) {
            $this->section($writer, 'AKCJE WEDLUG KRAJU (PIT/ZG)');
            $this->row($writer, ['Kraj', 'Nazwa', 'Przychod (PLN)', 'Koszty (PLN)', 'Dochod (PLN)']);
            foreach ($report->stock->pitZgCountries as $country) {
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

        $lossCountries = array_filter(
            $report->stock->countries,
            static fn ($country): bool => $country->income->isNegative(),
        );
        if ([] !== $lossCountries) {
            $this->section($writer, 'AUDYT FIFO - KRAJE ZE STRATA (BEZ PIT/ZG)');
            $this->row($writer, ['Kraj', 'Nazwa', 'Przychod (PLN)', 'Koszty (PLN)', 'Wynik (PLN)']);
            foreach ($lossCountries as $country) {
                $this->row($writer, [
                    $country->countryCode, $country->countryName ?? '',
                    (string) $country->revenue->value(), (string) $country->cost->value(),
                    (string) $country->income->value(),
                ]);
            }
            $this->row($writer, []);
        }

        if ([] !== $report->dividends->countries) {
            $this->section($writer, 'AUDYT DYWIDEND WEDLUG KRAJU (BEZ PIT/ZG)');
            $this->row($writer, [
                'Kraj', 'Nazwa', 'Przychod brutto (PLN)', 'Podatek pobrany (PLN)', 'Podatek polski (PLN)',
                'Do odliczenia (PLN)', 'Do zaplaty (PLN)',
            ]);
            foreach ($report->dividends->countries as $country) {
                $this->row($writer, [
                    $country->countryCode,
                    $country->countryName ?? '',
                    (string) $country->gross->value(),
                    (string) $country->withheldTax->value(),
                    (string) $country->polishTax->value(),
                    (string) $country->creditFor($chosen)->creditableTax->value(),
                    (string) $country->creditFor($chosen)->taxDue->value(),
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
            'Data zakupu', 'Cena/szt. zakupu', 'Waluta ceny zakupu', 'Kwota zakupu', 'Kurs NBP zakupu', 'Data kursu zakupu', 'Koszt (PLN)',
            'Prowizja zakupu', 'AutoFX zakupu',
            'Data sprzedazy', 'Cena/szt. sprzedazy', 'Waluta ceny sprzedazy', 'Kwota sprzedazy', 'Kwota nalezna (brutto)', 'Kurs NBP sprzedazy', 'Data kursu sprzedazy', 'Przychod (PLN)', 'Koszt zbycia (PLN)',
            'Prowizja sprzedazy', 'AutoFX sprzedazy',
            'Dochod (PLN)', 'Zrodlo',
            // Appended, so the columns above keep their positions for anyone
            // who reads this file by index.
            'Rodzaj instrumentu', 'Pozycja (dluga/krotka)', 'Data zamkniecia', 'Kurs NBP kosztu zbycia', 'Data kursu kosztu zbycia',
            'Metoda doboru partii', 'Data rozliczenia zakupu', 'Data rozliczenia sprzedazy',
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
            null === $position->position->buyUnitPrice ? '' : (string) $position->position->buyUnitPrice->value(),
            $position->position->buyUnitPrice?->currency() ?? '',
            (string) $position->position->buyAmount->value(),
            (string) $position->cost->rate,
            $position->cost->rateDate?->format('Y-m-d') ?? '',
            (string) $position->cost->pln->value(),
            null === $position->position->buyCommission ? '' : (string) $position->position->buyCommission->value(),
            null === $position->position->buyAutoFx ? '' : (string) $position->position->buyAutoFx->value(),
            $position->position->sellDate->format('Y-m-d'),
            null === $position->position->sellUnitPrice ? '' : (string) $position->position->sellUnitPrice->value(),
            $position->position->sellUnitPrice?->currency() ?? '',
            // Settled cash and the kwota należna side by side: the first is what
            // the broker's file says, the second is what PIT-38 declares, and
            // the rate column multiplies the second one.
            (string) $position->position->sellAmount->value(),
            (string) $position->revenue->original->value(),
            (string) $position->revenue->rate,
            $position->revenue->rateDate?->format('Y-m-d') ?? '',
            (string) $position->revenue->pln->value(),
            null === $position->disposalCost ? '' : (string) $position->disposalCost->pln->value(),
            null === $position->position->sellCommission ? '' : (string) $position->position->sellCommission->value(),
            null === $position->position->sellAutoFx ? '' : (string) $position->position->sellAutoFx->value(),
            (string) $position->income->value(),
            $position->position->source,
            $position->position->isOption() ? 'Opcja' : 'Akcje',
            $position->position->isShort() ? 'krotka' : 'dluga',
            $position->position->closeDate()->format('Y-m-d'),
            null === $position->disposalCost ? '' : (string) $position->disposalCost->rate,
            $position->disposalCost?->rateDate?->format('Y-m-d') ?? '',
            LotMethod::Specific === $position->position->lotMethod ? 'wskazanie partii' : 'FIFO',
            $position->position->buySettlement?->format('Y-m-d') ?? '',
            $position->position->sellSettlement?->format('Y-m-d') ?? '',
        ];
    }

    /** @param list<Trade> $trades */
    private function writeRawTrades(Writer $writer, array $trades): void
    {
        if ([] === $trades) {
            return;
        }
        $this->section($writer, 'AKTUALNY STAN - LOGICZNE TRANSAKCJE FIFO');
        $this->row($writer, [
            'Stabilne ID', 'Broker', 'Pula FIFO', 'Symbol/ISIN', 'Nazwa', 'Kraj', 'Data', 'Czas',
            'Kierunek', 'Liczba', 'Cena/szt.', 'Waluta ceny', 'Waluta', 'Total', 'Prowizja', 'AutoFX', 'Zrodlo',
            'Typ', 'Otwarcie/zamkniecie',
        ]);
        foreach ($trades as $trade) {
            $this->row($writer, [
                $trade->id(), $trade->broker, $trade->fifoPool ?: $trade->symbol, $trade->symbol,
                $trade->instrument->displayName ?? $trade->symbol,
                $trade->instrument->countryCode ?? '',
                $trade->date->format('Y-m-d'), $trade->date->format('H:i:s'),
                $trade->isBuy() ? 'BUY' : 'SELL', (string) $trade->quantity->abs(),
                null === $trade->unitPrice ? '' : (string) $trade->unitPrice->value(),
                $trade->unitPrice?->currency() ?? '',
                $trade->grossAmount->currency(), (string) $trade->grossAmount->value(),
                null === $trade->commission ? '' : (string) $trade->commission->value(),
                null === $trade->autoFx ? '' : (string) $trade->autoFx->value(),
                $trade->source,
                $trade->isOption() ? 'Opcja' : 'Akcje',
                $trade->effect->value ?? '',
            ]);
        }
        $this->row($writer, []);
    }

    /**
     * Which lots each sale named, so the choice can be audited - and typed back
     * - without the page. Every year, like the trades above it.
     *
     * @param list<Trade> $trades
     */
    private function writeLotAssignments(Writer $writer, LotAssignments $assignments, array $trades): void
    {
        if ($assignments->isEmpty()) {
            return;
        }
        $dates = [];
        foreach ($trades as $trade) {
            $dates[$trade->id()] = $trade->date->format('Y-m-d');
        }

        $this->section($writer, 'AKTUALNY STAN - WSKAZANE PARTIE');
        $this->row($writer, ['ID sprzedazy', 'Data sprzedazy', 'ID partii', 'Data zakupu partii', 'Liczba']);
        foreach ($assignments->all() as $saleId => $allocations) {
            foreach ($allocations as $allocation) {
                $this->row($writer, [
                    $saleId, $dates[$saleId] ?? '',
                    $allocation->buyTradeId, $dates[$allocation->buyTradeId] ?? '',
                    (string) $allocation->quantity,
                ]);
            }
        }
        $this->row($writer, []);
    }

    /** @param list<AccountFee> $fees */
    private function writeFees(Writer $writer, array $fees, TaxReport $report): void
    {
        if ([] === $fees) {
            return;
        }
        $calculated = [];
        foreach ($report->stock->accountingFees as $item) {
            $calculated[$item->fee->id()] = $item;
        }
        $this->section($writer, 'SAMODZIELNE OPLATY RACHUNKOWE');
        $this->row($writer, [
            'Stabilne ID', 'Opis', 'Kategoria', 'Data waluty', 'Waluta', 'Kwota', 'Korekta/zwrot',
            'Uwzgledniona', 'Kurs NBP', 'Data kursu', 'Wplyw na koszt PLN', 'Zrodlo',
        ]);
        foreach ($fees as $fee) {
            $item = $calculated[$fee->id()] ?? null;
            $this->row($writer, [
                $fee->id(), $fee->description, $fee->category, $fee->valueDate->format('Y-m-d'),
                $fee->currency, (string) $fee->amount->value(), $fee->correction ? 'tak' : 'nie',
                $fee->included ? 'tak' : 'nie',
                null === $item ? '' : (string) $item->exchanged->rate,
                $item->exchanged->rateDate?->format('Y-m-d') ?? '',
                null === $item ? '' : (string) $item->costImpact->value(),
                $fee->source,
            ]);
        }
        $this->row($writer, []);
    }

    /** @param list<Dividend> $dividends */
    private function writeCurrentDividends(Writer $writer, array $dividends): void
    {
        if ([] === $dividends) {
            return;
        }

        $this->section($writer, 'AKTUALNY STAN - DYWIDENDY');
        $this->row($writer, [
            'Stabilne ID', 'Instrument', 'Kraj', 'Data wyplaty', 'Waluta', 'Brutto', 'Podatek u zrodla', 'Zrodlo',
        ]);
        foreach ($dividends as $dividend) {
            $this->row($writer, [
                $dividend->id(), $dividend->name, $dividend->countryCode, $dividend->date->format('Y-m-d'),
                $dividend->currency, (string) $dividend->grossAmount->value(),
                (string) $dividend->withheldTax->value(), $dividend->source,
            ]);
        }
        $this->row($writer, []);
    }

    private function writeDividends(Writer $writer, TaxReport $report, CreditMethod $chosen): void
    {
        if ([] === $report->dividends->dividends) {
            return;
        }

        $this->section($writer, 'SZCZEGOLY - DYWIDENDY');
        $this->row($writer, [
            'Lp.', 'Instrument', 'Kraj', 'Waluta', 'Data wyplaty',
            'Brutto', 'Kurs NBP', 'Data kursu', 'Brutto (PLN)',
            'Podatek pobrany', 'Podatek pobrany (PLN)', 'Stawka umowna (%)', 'Podatek polski (PLN)',
            'Do odliczenia (PLN)', 'Do zaplaty (PLN)', 'Zrodlo', 'Uwagi',
        ]);

        foreach ($report->dividends->dividends as $index => $dividend) {
            $this->row($writer, $this->dividendRow($index + 1, $dividend, $chosen));
        }

        $this->row($writer, []);
    }

    /**
     * @return list<string>
     */
    private function dividendRow(int $number, CalculatedDividend $dividend, CreditMethod $chosen): array
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
            (string) $dividend->creditFor($chosen)->creditableTax->toScale(self::PLN_SCALE)->value(),
            (string) $dividend->creditFor($chosen)->taxDue->toScale(self::PLN_SCALE)->value(),
            $dividend->dividend->source,
            $dividend->warning ?? '',
        ];
    }

    private function writeMessages(Writer $writer, TaxReport $report): void
    {
        $messages = [...$report->errors, ...$report->warnings];
        foreach ($report->stock->positions as $position) {
            if (LotMethod::Specific === $position->position->lotMethod) {
                $messages[] = self::SPECIFIC_LOT_NOTE;
                break;
            }
        }
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

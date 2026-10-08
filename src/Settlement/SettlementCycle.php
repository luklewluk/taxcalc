<?php

declare(strict_types=1);

namespace App\Settlement;

/**
 * Which day of a trade leg converts it to złoty and decides its tax year.
 *
 * Art. 11a takes the NBP rate from the business day before the day income
 * arises or a cost is incurred. One reading puts that day on the trade itself;
 * another on settlement, when ownership of dematerialised securities actually
 * passes. The law leaves both open, so this is a setting, never a finding.
 */
enum SettlementCycle: string
{
    case TradeDate = 'trade_date';
    case Market = 'market';
    case PolishD2 = 'pl_d2';

    public function label(): string
    {
        return match ($this) {
            self::TradeDate => 'Data transakcji (D+0)',
            self::Market => 'Dzień rozliczenia wg giełdy (D+1 / D+2)',
            self::PolishD2 => 'Dzień rozliczenia, kalendarz polski (D+2)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TradeDate => 'Kurs NBP z dnia roboczego przed dniem zawarcia transakcji, a rok podatkowy '
                .'według daty zawarcia. Tak kalkulator liczył dotąd.',
            self::Market => 'Kurs NBP z dnia roboczego przed dniem rozliczenia, liczonym w dniach roboczych '
                .'rynku, na którym zawarto transakcję: akcje i ETF-y dwa dni robocze (w USA, Kanadzie '
                .'i Meksyku jeden dzień od końca maja 2024 r.), opcje jeden dzień. O roku podatkowym '
                .'też decyduje dzień rozliczenia.',
            self::PolishD2 => 'Kurs NBP z dnia roboczego przed dniem rozliczenia, liczonym w polskich dniach '
                .'roboczych: akcje i ETF-y dwa dni, opcje jeden dzień - bez względu na rynek. O roku '
                .'podatkowym też decyduje dzień rozliczenia.',
        };
    }
}

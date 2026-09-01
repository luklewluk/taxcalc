<?php

declare(strict_types=1);

namespace App\Import;

enum CsvFormat: string
{
    case IbkrTrades = 'ibkr_trades';
    case IbkrActivityDividends = 'ibkr_activity_dividends';
    case IbkrDividendDetail = 'ibkr_dividend_detail';
    case DegiroTransactions = 'degiro_transactions';
    case DegiroAccount = 'degiro_account';
    case NormalizedPositions = 'normalized_positions';
    case NormalizedDividends = 'normalized_dividends';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::IbkrTrades => 'IBKR - transakcje giełdowe',
            self::IbkrActivityDividends => 'IBKR - dywidendy (zestawienie aktywności)',
            self::IbkrDividendDetail => 'IBKR - dywidendy (Dividend Detail / dokumenty podatkowe)',
            self::DegiroTransactions => 'DEGIRO - transakcje giełdowe',
            self::DegiroAccount => 'DEGIRO - zestawienie konta (dywidendy)',
            self::NormalizedPositions => 'Format własny - pozycje zamknięte',
            self::NormalizedDividends => 'Format własny - dywidendy',
            self::Unknown => 'Nierozpoznany',
        };
    }
}

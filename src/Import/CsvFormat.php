<?php

declare(strict_types=1);

namespace App\Import;

enum CsvFormat: string
{
    case IbkrActivityStatement = 'ibkr_activity_statement';
    case DegiroTransactions = 'degiro_transactions';
    case DegiroAccount = 'degiro_account';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::IbkrActivityStatement => 'IBKR - wyciąg z aktywności (Activity Statement)',
            self::DegiroTransactions => 'DEGIRO - transakcje giełdowe',
            self::DegiroAccount => 'DEGIRO - zestawienie konta (dywidendy)',
            self::Unknown => 'Nierozpoznany',
        };
    }
}

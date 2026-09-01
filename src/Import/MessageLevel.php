<?php

declare(strict_types=1);

namespace App\Import;

enum MessageLevel: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Error => 'Błąd',
            self::Warning => 'Ostrzeżenie',
            self::Info => 'Informacja',
        };
    }
}

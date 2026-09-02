<?php

declare(strict_types=1);

namespace App\Import;

enum MessageLevel: string
{
    case Error = 'error';
    /**
     * Not fatal, but the user has to act on it - a reversal that cannot be
     * settled, say. Unlike a plain warning it gets a place in the web
     * workbench's "Wymaga uwagi" panel; see
     * {@see \App\Controller\CalculatorController::importDiagnostics()}.
     */
    case Review = 'review';
    case Warning = 'warning';
    case Info = 'info';

    public function label(): string
    {
        return match ($this) {
            self::Error => 'Błąd',
            self::Review => 'Do weryfikacji',
            self::Warning => 'Ostrzeżenie',
            self::Info => 'Informacja',
        };
    }
}

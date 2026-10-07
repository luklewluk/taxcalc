<?php

declare(strict_types=1);

namespace App\Fifo;

/**
 * Which side opened a position. Long: bought, then sold. Short: written (sold)
 * first and closed by a buy - which exists only for options here.
 */
enum PositionDirection: string
{
    case Long = 'long';
    case Short = 'short';

    public function label(): string
    {
        return match ($this) {
            self::Long => 'długa',
            self::Short => 'krótka',
        };
    }
}

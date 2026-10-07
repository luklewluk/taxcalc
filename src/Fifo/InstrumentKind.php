<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Exception\InvalidRecordException;

/**
 * What a trade buys or sells. Stocks (and ETFs) settle on the sale; options
 * settle whenever the position closes, which for a written option is a buy.
 */
enum InstrumentKind: string
{
    case Stock = 'STK';
    case Option = 'OPT';

    /**
     * A blank value is a stock: every form posted before options existed.
     */
    public static function fromForm(string $value): self
    {
        $value = strtoupper(trim($value));
        if ('' === $value) {
            return self::Stock;
        }

        return self::tryFrom($value) ?? throw new InvalidRecordException(sprintf(
            'Nieobsługiwany rodzaj instrumentu "%s" - dozwolone są akcje/ETF (STK) i opcje (OPT).',
            $value,
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Stock => 'Akcje / ETF',
            self::Option => 'Opcja',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Fifo;

use App\Exception\InvalidRecordException;

/**
 * Whether an option trade opens or closes a position, as the broker declared
 * it (IBKR codes `O` / `C`).
 *
 * Declared rather than inferred on purpose: a buy-to-close whose writing sale
 * sits in a statement that was not uploaded would otherwise open a long lot and
 * the premium income would vanish without a trace.
 */
enum PositionEffect: string
{
    case Open = 'open';
    case Close = 'close';
    /** One order that closes the whole position and opens the opposite one with the rest. */
    case CloseThenOpen = 'close_open';

    /**
     * Blank means "not declared", which is an error only for an option.
     */
    public static function fromForm(string $value): ?self
    {
        $value = strtolower(trim($value));
        if ('' === $value) {
            return null;
        }

        return self::tryFrom($value) ?? throw new InvalidRecordException(sprintf(
            'Nieznany rodzaj transakcji opcyjnej "%s" - dozwolone: otwarcie, zamknięcie, zamknięcie i otwarcie.',
            $value,
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Otwarcie',
            self::Close => 'Zamknięcie',
            self::CloseThenOpen => 'Zamknięcie i otwarcie',
        };
    }
}

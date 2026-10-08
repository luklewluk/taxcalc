<?php

declare(strict_types=1);

namespace App\Fifo;

/**
 * How a sale's lots were chosen. FIFO is the default; a sale the user tied to
 * named lots is a specific identification - allowed when the broker let the
 * units sold be identified (art. 30b ust. 7 ustawy o PIT; KIS
 * 0112-KDIL2-1.4011.929.2025.1.TR of 13.02.2026).
 *
 * Never part of a lineage key or a fingerprint: naming the lot FIFO would pick
 * anyway must not change the identity of the position.
 */
enum LotMethod: string
{
    case Fifo = 'fifo';
    case Specific = 'specific';

    public function label(): string
    {
        return match ($this) {
            self::Fifo => 'FIFO',
            self::Specific => 'wskazane',
        };
    }
}

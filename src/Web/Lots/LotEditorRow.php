<?php

declare(strict_types=1);

namespace App\Web\Lots;

use App\Money\Decimal;

final readonly class LotEditorRow
{
    /**
     * @param array<string, string> $row
     */
    public function __construct(
        public int $index,
        public string $tradeId,
        public string $label,
        public array $row,
        public Decimal $available,
        public string $value,
    ) {
    }
}

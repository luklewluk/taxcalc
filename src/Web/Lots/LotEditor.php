<?php

declare(strict_types=1);

namespace App\Web\Lots;

use App\Money\Decimal;

/**
 * The open editor of one sale: every lot open at that moment, prefilled.
 */
final readonly class LotEditor
{
    /**
     * @param array<string, string> $row
     * @param list<LotEditorRow>    $rows
     * @param list<string>          $errors
     */
    public function __construct(
        public string $saleId,
        public string $label,
        public array $row,
        public Decimal $soldQuantity,
        public array $rows,
        public array $errors,
    ) {
    }

    /**
     * @return list<string>
     */
    public function lotIds(): array
    {
        return array_map(static fn (LotEditorRow $row): string => $row->tradeId, $this->rows);
    }
}

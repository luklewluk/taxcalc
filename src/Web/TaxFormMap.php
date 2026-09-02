<?php

declare(strict_types=1);

namespace App\Web;

/** Official PIT-38/PIT-ZG field numbering, versioned by settlement year. */
final readonly class TaxFormMap
{
    /** @return array<string, int|string|bool> */
    public function forYear(int $year): array
    {
        $new = $year >= 2025;

        return [
            'pit38_version' => match ($year) {
                2021 => 15,
                2022, 2023 => 16,
                2024 => 17,
                default => 18,
            },
            'pitzg_version' => $year <= 2022 ? 7 : 8,
            'stock_revenue' => 22,
            'stock_cost' => 23,
            'total_revenue' => $new ? 26 : 24,
            'total_cost' => $new ? 27 : 25,
            'stock_income' => $new ? 28 : 26,
            'stock_loss' => $new ? 29 : 27,
            'tax_basis' => $new ? 31 : 29,
            'tax_rate' => $new ? 32 : 30,
            'stock_tax' => $new ? 33 : 31,
            'foreign_stock_tax' => $new ? 34 : 32,
            'stock_tax_due' => $new ? 35 : 33,
            'dividend_polish_tax' => $new ? 47 : 45,
            'dividend_foreign_tax' => $new ? 48 : 46,
            'dividend_tax_due' => $new ? 49 : 47,
            'total_tax' => $new ? 51 : 49,
            'pitzg_count' => $new ? 72 : 69,
            'pitzg_income' => $year <= 2022 ? 32 : 29,
            'pitzg_tax' => $year <= 2022 ? 33 : 30,
            'provisional' => 2026 === $year,
        ];
    }
}

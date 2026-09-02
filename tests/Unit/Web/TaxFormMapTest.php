<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Web\TaxFormMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxFormMapTest extends TestCase
{
    #[DataProvider('years')]
    public function testOfficialVersionedFields(int $year, array $expected): void
    {
        self::assertSame($expected, (new TaxFormMap())->forYear($year));
    }

    public static function years(): array
    {
        $old = static fn (int $pitVersion, int $zgVersion, int $zgIncome): array => [
            'pit38_version' => $pitVersion, 'pitzg_version' => $zgVersion,
            'stock_revenue' => 22, 'stock_cost' => 23, 'total_revenue' => 24, 'total_cost' => 25,
            'stock_income' => 26, 'stock_loss' => 27, 'tax_basis' => 29, 'tax_rate' => 30,
            'stock_tax' => 31, 'foreign_stock_tax' => 32, 'stock_tax_due' => 33,
            'dividend_polish_tax' => 45, 'dividend_foreign_tax' => 46, 'dividend_tax_due' => 47,
            'total_tax' => 49, 'pitzg_count' => 69, 'pitzg_income' => $zgIncome, 'pitzg_tax' => $zgIncome + 1,
            'provisional' => false,
        ];
        $new = static fn (bool $provisional): array => [
            'pit38_version' => 18, 'pitzg_version' => 8,
            'stock_revenue' => 22, 'stock_cost' => 23, 'total_revenue' => 26, 'total_cost' => 27,
            'stock_income' => 28, 'stock_loss' => 29, 'tax_basis' => 31, 'tax_rate' => 32,
            'stock_tax' => 33, 'foreign_stock_tax' => 34, 'stock_tax_due' => 35,
            'dividend_polish_tax' => 47, 'dividend_foreign_tax' => 48, 'dividend_tax_due' => 49,
            'total_tax' => 51, 'pitzg_count' => 72, 'pitzg_income' => 29, 'pitzg_tax' => 30,
            'provisional' => $provisional,
        ];

        return [
            [2021, $old(15, 7, 32)], [2022, $old(16, 7, 32)],
            [2023, $old(16, 8, 29)], [2024, $old(17, 8, 29)],
            [2025, $new(false)], [2026, $new(true)],
        ];
    }
}

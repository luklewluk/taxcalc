<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Importer;

use App\Fifo\FifoMatcher;
use App\Import\CsvSource;
use App\Import\Importer\DegiroTransactionsImporter;
use PHPUnit\Framework\TestCase;

final class DegiroTradeAuditFeeTest extends TestCase
{
    public function testExplicitCommissionAndZeroAutoFxStayDistinctFromMissingData(): void
    {
        $new = "Data,Czas,Produkt,ISIN,Giełda referencyjna,Miejsce wykonania,Liczba,Kurs,,Wartość lokalna,,Wartość EUR,Kurs wymiany,Opłaty AutoFX,Opłata transakcyjna DEGIRO i/lub opłata stron,Razem EUR,Identyfikator zlecenia,\n"
            ."03-04-2024,09:15,ALFA,US000ALFA001,NDQ,XNAS,1,100,EUR,-100,EUR,-100,,0,-3.90,-103.90,,buy-1\n";
        $trade = self::importer()->extractTrades(new CsvSource('new.csv', $new))->trades[0];

        self::assertSame('3.90', (string) $trade->commission?->value());
        self::assertSame('0', (string) $trade->autoFx?->value());

        $old = "Date,Time,Product,ISIN,Quantity,Price,,Transaction and/or third,,Total,,Order ID\n"
            ."03-04-2024,09:15,ALFA,US000ALFA001,1,100,USD,-1.00,USD,-101.00,USD,buy-1\n";
        $oldTrade = self::importer()->extractTrades(new CsvSource('old.csv', $old))->trades[0];
        self::assertSame('1.00', (string) $oldTrade->commission?->value());
        self::assertNull($oldTrade->autoFx);
    }

    private static function importer(): DegiroTransactionsImporter
    {
        return new DegiroTransactionsImporter(new FifoMatcher());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Importer;

use App\Import\CsvSource;
use App\Import\Importer\DegiroAccountImporter;
use PHPUnit\Framework\TestCase;

final class DegiroAccountFeeImporterTest extends TestCase
{
    private const string HEADER = 'Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id';

    /**
     * The fee comes out of the same statement and the same column pair as a
     * dividend, so the same rule applies: the year is the day the money left
     * the cash balance, i.e. the booking date.
     */
    public function testImportsOnlyExactConnectionFeeAndUsesTheBookingDate(): void
    {
        $csv = self::HEADER."\n"
            .'02-01-2025,00:00,29-12-2024,,,DEGIRO Exchange Connection Fee,,EUR,-2.50,EUR,100.00,'."\n"
            .'03-01-2025,00:00,03-01-2025,,,Some Exchange Connection Fee,,EUR,-9.00,EUR,91.00,'."\n";
        $result = (new DegiroAccountImporter())->import(new CsvSource('account.csv', $csv));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->fees);
        self::assertSame(2025, $result->fees[0]->taxYear());
        self::assertSame('2.50', (string) $result->fees[0]->amount->value());
        self::assertTrue($result->fees[0]->included);
    }

    /**
     * DEGIRO charges one fee per exchange a year and names both in the
     * description. The year and the venue are read past; what is left still
     * has to match exactly, so a look-alike stays out.
     */
    public function testAFeeNamedAfterItsYearAndExchangeIsStillAConnectionFee(): void
    {
        $csv = self::HEADER."\n"
            .'03-02-2025,00:00,03-02-2025,,,DEGIRO Exchange Connection Fee 2025 (Xetra - XET),,EUR,-2.50,EUR,100.00,'."\n"
            .'03-02-2025,00:00,03-02-2025,,,DEGIRO Exchange Connection Fee 2025 (London Stock Exchange (LSE) - LSE),,EUR,-2.50,EUR,97.50,'."\n"
            .'03-02-2025,00:00,03-02-2025,,,DEGIRO Opłata za połączenie z giełdą 2025 (Nasdaq - NDQ),,EUR,-2.50,EUR,95.00,'."\n"
            .'04-02-2025,00:00,04-02-2025,,,Some Exchange Connection Fee 2025 (Xetra - XET),,EUR,-9.00,EUR,86.00,'."\n";
        $result = (new DegiroAccountImporter())->import(new CsvSource('account.csv', $csv));

        self::assertSame([], $result->errors());
        self::assertCount(3, $result->fees);
        self::assertSame('DEGIRO Exchange Connection Fee 2025 (London Stock Exchange (LSE) - LSE)', $result->fees[1]->description);
        self::assertSame(2025, $result->fees[0]->taxYear());
        self::assertNotSame($result->fees[0]->id(), $result->fees[1]->id());
    }

    public function testACorrectionNamedAfterItsYearAndExchangeNetsTheFee(): void
    {
        $csv = self::HEADER."\n"
            .'03-02-2025,00:00,03-02-2025,,,DEGIRO Exchange Connection Fee 2025 (Xetra - XET),,EUR,-2.50,EUR,100.00,'."\n"
            .'05-02-2025,00:00,05-02-2025,,,DEGIRO Exchange Connection Fee Correction 2025 (Xetra - XET),,EUR,2.50,EUR,102.50,'."\n";
        $result = (new DegiroAccountImporter())->import(new CsvSource('account.csv', $csv));

        self::assertSame([], $result->errors());
        self::assertSame([], $result->fees);
    }

    /**
     * A transaction tax is not an account fee: it belongs to one purchase, so
     * it leaves the importer as a TransactionTax for the import service to add
     * to that purchase - counted once across overlapping statements.
     */
    public function testATransactionTaxIsHandedOverForItsPurchase(): void
    {
        $row = '10-03-2023,09:01,10-03-2023,ALFA SA,FR000ALFA001,Francuski podatek od transakcji,,EUR,-3.00,EUR,97.00,ftt-1'."\n";
        $csv = self::HEADER."\n".$row;
        $result = (new DegiroAccountImporter())->importMany([
            new CsvSource('a.csv', $csv),
            new CsvSource('b.csv', $csv),
        ]);

        self::assertSame([], $result->errors());
        self::assertSame([], $result->fees);
        self::assertCount(1, $result->transactionTaxes);
        $tax = $result->transactionTaxes[0];
        self::assertSame('FR000ALFA001', $tax->isin);
        self::assertSame('3.00', (string) $tax->amount->value());
        self::assertSame('2023-03-10', $tax->date->format('Y-m-d'));
        self::assertSame('DEGIRO', $tax->broker);
    }

    public function testTheTaxIsRecognisedInOtherLanguagesAndARefundIsLeftForReview(): void
    {
        $csv = self::HEADER."\n"
            .'10-03-2023,09:01,10-03-2023,ALFA SA,FR000ALFA001,French Transaction Tax,,EUR,-3.00,EUR,97.00,'."\n"
            .'11-03-2023,09:01,11-03-2023,BETA SPA,IT000BETA002,Italian Financial Transaction Tax,,EUR,-1.00,EUR,96.00,'."\n"
            .'12-03-2023,09:01,12-03-2023,ALFA SA,FR000ALFA001,Francuski podatek od transakcji,,EUR,3.00,EUR,99.00,'."\n";
        $result = (new DegiroAccountImporter())->import(new CsvSource('account.csv', $csv));

        self::assertSame([], $result->errors());
        self::assertCount(2, $result->transactionTaxes);
        self::assertStringContainsString('Rozlicz', implode(' ', $result->warnings()));
    }

    public function testFullyReversedFeeGroupIsOmitted(): void
    {
        $csv = self::HEADER."\n"
            .'02-01-2025,00:00,02-01-2025,,,DEGIRO Exchange Connection Fee,,EUR,-2.50,EUR,100.00,'."\n"
            .'03-01-2025,00:00,03-01-2025,,,DEGIRO Exchange Connection Fee Correction,,EUR,2.50,EUR,102.50,'."\n";
        $result = (new DegiroAccountImporter())->import(new CsvSource('account.csv', $csv));

        self::assertSame([], $result->errors());
        self::assertSame([], $result->fees);
        self::assertStringContainsString('wyzerowan', mb_strtolower(implode(' ', $result->infos())));
    }

    public function testRefundAboveChargesFailsClosed(): void
    {
        $csv = self::HEADER."\n"
            .'02-01-2025,00:00,02-01-2025,,,DEGIRO Exchange Connection Fee,,EUR,-2.50,EUR,100.00,'."\n"
            .'03-01-2025,00:00,03-01-2025,,,DEGIRO Exchange Connection Fee Correction,,EUR,3.00,EUR,103.00,'."\n";
        $result = (new DegiroAccountImporter())->import(new CsvSource('account.csv', $csv));

        self::assertNotEmpty($result->errors());
    }
}

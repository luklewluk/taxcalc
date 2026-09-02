<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Importer;

use App\Import\CsvFormat;
use App\Import\CsvSource;
use App\Import\Importer\DegiroAccountImporter;
use App\Import\ImportResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DegiroAccountImporter::class)]
final class DegiroAccountImporterTest extends TestCase
{
    /**
     * The 12-column statement. `Change` names the *currency*; the amount is in
     * the unnamed column after it, and the same trick repeats for `Balance`.
     */
    private const string HEADER_EN = 'Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id';

    public function testSupportsOnlyItsOwnFormat(): void
    {
        $importer = new DegiroAccountImporter();

        self::assertTrue($importer->supports(CsvFormat::DegiroAccount));
        self::assertFalse($importer->supports(CsvFormat::DegiroTransactions));
    }

    public function testReadsADividendWithItsWithholdingTax(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-0.38,USD,499.62,',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);

        $dividend = $result->dividends[0];
        self::assertSame('ALFA CORP', $dividend->name);
        self::assertSame('US', $dividend->countryCode);
        self::assertSame('USD', $dividend->currency);
        self::assertSame('2025-05-15', $dividend->date->format('Y-m-d'));
        self::assertSame('2.50', (string) $dividend->grossAmount->value());
        self::assertSame('0.38', (string) $dividend->withheldTax->value());
    }

    public function testNeverProducesPositionsBecauseTradesComeFromTheTransactionExport(): void
    {
        $result = self::import([
            '03-04-2024,09:15,03-04-2024,ALFA CORP,US000ALFA001,"Buy 10 ALFA CORP@100,00 USD",,USD,-1000.00,USD,500.00,buy-1',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,',
        ]);

        self::assertSame([], $result->positions);
        self::assertCount(1, $result->dividends);
    }

    /**
     * Income exists on the day the money is received or placed at the
     * taxpayer's disposal (art. 11 ust. 1), and the NBP rate comes from the
     * last business day before *that* day (art. 11a). DEGIRO books a dividend
     * once the custodian confirms it has the cash, so the booking date is when
     * the balance actually changed; the value date is the issuer's payable
     * date and does not prove the money was available.
     */
    public function testTheBookingDateSettlesTheYearBecauseThatIsWhenTheCashLanded(): void
    {
        $result = self::import([
            '02-01-2025,00:00,29-12-2024,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,',
            '02-01-2025,00:00,29-12-2024,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-1.50,USD,498.50,',
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('2025-01-02', $result->dividends[0]->date->format('Y-m-d'));
        self::assertSame(2025, $result->dividends[0]->taxYear());
    }

    public function testTheFirstGenericDateColumnIsTheBookingDate(): void
    {
        $content = "Data,Czas,Data,Produkt,ISIN,Opis,Kurs,Zmiana,,Saldo,,Identyfikator zlecenia\n"
            ."02-01-2025,00:00,29-12-2024,ALFA CORP,US000ALFA001,Dywidenda,,USD,10.00,USD,500.00,\n";

        $result = (new DegiroAccountImporter())->import(new CsvSource('rachunek.csv', $content));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('2025-01-02', $result->dividends[0]->date->format('Y-m-d'));
    }

    public function testTheValueDateIsOnlyAFallbackWhenTheExportHasNoBookingColumn(): void
    {
        $content = "Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n"
            ."29-12-2024,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,\n";

        $result = (new DegiroAccountImporter())->import(new CsvSource('rachunek.csv', $content));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('2024-12-29', $result->dividends[0]->date->format('Y-m-d'));
    }

    /**
     * DEGIRO reverses an old payment by posting the opposite entries *today*
     * against the original value date. Keying the payment group on the booking
     * date keeps the two apart, so the original stays in the year it was paid
     * instead of being netted to zero and vanishing from that year's return.
     */
    public function testAReversalDoesNotCancelTheOriginalPaymentItCorrects(): void
    {
        $result = self::import([
            '15-11-2024,00:00,14-11-2024,ZETA TRUST,US000ZETA001,Dividend,,USD,0.40,USD,10.00,',
            '15-11-2024,00:00,14-11-2024,ZETA TRUST,US000ZETA001,Dividend Tax,,USD,-0.06,USD,9.94,',
            '12-12-2025,00:00,14-11-2024,ZETA TRUST,US000ZETA001,Dividend,,USD,-0.40,USD,9.54,',
            '12-12-2025,00:00,14-11-2024,ZETA TRUST,US000ZETA001,Dividend Tax,,USD,0.06,USD,9.60,',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('2024-11-15', $result->dividends[0]->date->format('Y-m-d'));
        self::assertSame('0.40', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('0.06', (string) $result->dividends[0]->withheldTax->value());
    }

    /**
     * DEGIRO also corrects a payment *within* the year: it reverses it on one
     * day and re-posts it on the next. Those bookings describe one payment, so
     * they have to net - keying purely on the booking date would settle both the
     * original and the re-post and count the dividend twice.
     */
    public function testCorrectionsPostedInTheSameYearNetIntoOnePayment(): void
    {
        $result = self::import([
            '12-09-2025,00:00,11-09-2025,OMEGA SOFT,US000OMEGA01,Dividend,,USD,6.00,USD,10.00,',
            '12-09-2025,00:00,11-09-2025,OMEGA SOFT,US000OMEGA01,Dividend Tax,,USD,-0.90,USD,9.25,',
            '03-12-2025,00:00,11-09-2025,OMEGA SOFT,US000OMEGA01,Dividend,,USD,-6.00,USD,4.27,',
            '03-12-2025,00:00,11-09-2025,OMEGA SOFT,US000OMEGA01,Dividend Tax,,USD,0.90,USD,5.02,',
            '04-12-2025,00:00,11-09-2025,OMEGA SOFT,US000OMEGA01,Dividend,,USD,6.00,USD,10.00,',
            '04-12-2025,00:00,11-09-2025,OMEGA SOFT,US000OMEGA01,Dividend Tax,,USD,-0.90,USD,9.25,',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('6.00', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('0.90', (string) $result->dividends[0]->withheldTax->value());
        // The day the cash first landed, so the rate is D-1 from it.
        self::assertSame('2025-09-12', $result->dividends[0]->date->format('Y-m-d'));
        self::assertStringNotContainsString('storn', mb_strtolower(implode(' ', $result->warnings())));
    }

    /**
     * A reversal on its own is not a negative dividend: `Dividend` refuses a
     * non-positive gross, so building one would fail the whole batch on a
     * perfectly ordinary statement.
     */
    public function testAStandaloneReversalIsReportedRatherThanSettled(): void
    {
        $result = self::import([
            '12-12-2025,00:00,14-11-2024,ZETA TRUST,US000ZETA001,Dividend,,USD,-0.40,USD,9.54,',
            '12-12-2025,00:00,14-11-2024,ZETA TRUST,US000ZETA001,Dividend Tax,,USD,0.06,USD,9.60,',
        ]);

        self::assertSame([], $result->errors());
        self::assertSame([], $result->dividends);

        $warnings = implode(' ', $result->warnings());
        self::assertStringContainsString('ZETA TRUST', $warnings);
        self::assertStringContainsString('2024', $warnings, 'komunikat musi wskazać rok korekty');
        self::assertStringContainsString('storn', mb_strtolower($warnings));
    }

    public function testAWithholdingRefundLargerThanTheGroupTaxIsReportedNotSettled(): void
    {
        $result = self::import([
            '12-12-2025,00:00,12-12-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,',
            '12-12-2025,00:00,12-12-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,1.50,USD,501.50,',
        ]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testRecognisesTheCurrentPolishDividendTaxDescription(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dywidenda,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Podatek Dywidendowy,,USD,-1.50,USD,498.50,',
        ]);

        self::assertSame([], $result->errors());
        self::assertSame('1.50', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testBrokerCurrencyAbbreviationsAreNormalised(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,NORDIC CORP,NO000NORD001,Dywidenda,,NO,10.00,NO,500.00,',
            '16-05-2025,00:00,16-05-2025,SINGAPORE CORP,SG000SING002,Dywidenda,,SG,20.00,SG,500.00,',
        ]);

        self::assertSame([], $result->errors());
        self::assertSame(['NOK', 'SGD'], array_map(static fn ($dividend): string => $dividend->currency, $result->dividends));
    }

    public function testAReversedPaymentNettedToZeroIsSkippedWithInformation(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dywidenda,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dywidenda (korekta),,USD,-10.00,USD,490.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Podatek Dywidendowy,,USD,-1.50,USD,488.50,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Podatek Dywidendowy (korekta),,USD,1.50,USD,490.00,',
        ]);

        self::assertSame([], $result->errors());
        self::assertSame([], $result->dividends);
        self::assertStringContainsString('zero', mb_strtolower(implode(' ', $result->infos())));
    }

    public function testZeroGrossWithNonZeroTaxAfterCorrectionIsFatal(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dywidenda,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dywidenda (korekta),,USD,-10.00,USD,490.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Podatek Dywidendowy,,USD,-1.50,USD,488.50,',
        ]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testBuyDescriptionContainingDividendIsClassifiedAsATradeFirst(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,DIVIDEND GROWTH ETF,IE000BETA002,Buy 10 DIVIDEND GROWTH ETF,,EUR,-100.00,EUR,400.00,',
            '15-05-2025,00:00,15-05-2025,DIVIDEND GROWTH ETF,IE000BETA002,Product Change DIVIDEND GROWTH ETF,,EUR,0.00,EUR,400.00,',
            '16-05-2025,00:00,16-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('ALFA CORP', $result->dividends[0]->name);
    }

    public function testUnsupportedCapitalDistributionsAreWarnedAndNotCalculated(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Capital Return,,USD,10.00,USD,500.00,',
            '16-05-2025,00:00,16-05-2025,BETA ETF,IE000BETA002,QIE Distribution Capital Gain,,EUR,7.00,EUR,507.00,',
        ]);

        self::assertSame([], $result->errors());
        self::assertSame([], $result->dividends);
        $warnings = mb_strtolower(implode(' ', $result->warnings()));
        self::assertStringContainsString('capital return', $warnings);
        self::assertStringContainsString('qie distribution capital gain', $warnings);
        self::assertStringContainsString('ręczn', $warnings);
    }

    public function testSeveralRowsOfOnePaymentAreSummedIntoOneRecord(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,1.50,USD,501.50,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-0.38,USD,501.12,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-0.22,USD,500.90,',
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('4.00', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('0.60', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testARefundedWithholdingRowReducesTheTaxPaid(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-3.00,USD,497.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,1.00,USD,498.00,',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('2.00', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testARefundBiggerThanTheChargeIsRefusedInsteadOfFlippingTheSign(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,2.00,USD,502.00,',
        ]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    /**
     * Still never taxed - but reported instead of fatal. A lone negative gross
     * is DEGIRO reversing an earlier payment, which is an ordinary entry in a
     * long statement; failing the batch over it would block every other year.
     */
    public function testANegativeGrossDividendIsReportedRatherThanTaxed(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,-10.00,USD,490.00,',
        ]);

        self::assertSame([], $result->dividends);
        self::assertSame([], $result->errors());
        self::assertStringContainsString('storn', mb_strtolower(implode(' ', $result->warnings())));
    }

    public function testWithholdingWithNoDividendIsFatalBecauseTheGrossIncomeIsMissing(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-0.38,USD,499.62,',
        ]);

        self::assertSame([], $result->dividends);
        self::assertStringContainsString('nie pasuje', implode(' ', $result->errors()));
    }

    #[DataProvider('localizedDescriptions')]
    public function testRecognisesDividendAndTaxRowsInEveryExportLanguage(
        string $header,
        string $dividendRow,
        string $taxRow,
        string $expectedGross,
        string $expectedTax,
    ): void {
        $content = $header."\n".$dividendRow."\n".$taxRow."\n";
        $result = (new DegiroAccountImporter())->import(new CsvSource('rachunek.csv', $content));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame($expectedGross, (string) $result->dividends[0]->grossAmount->value());
        self::assertSame($expectedTax, (string) $result->dividends[0]->withheldTax->value());
    }

    /**
     * @return iterable<string, array{string, string, string, string, string}>
     */
    public static function localizedDescriptions(): iterable
    {
        yield 'english' => [
            self::HEADER_EN,
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Withholding Tax,,USD,-1.50,USD,498.50,',
            '10.00',
            '1.50',
        ];

        yield 'dutch' => [
            'Datum,Tijd,Valutadatum,Product,ISIN,Omschrijving,FX,Mutatie,,Saldo,,Order Id',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividende,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividendbelasting,,USD,-1.50,USD,498.50,',
            '10.00',
            '1.50',
        ];

        yield 'polish with continental decimals and semicolons' => [
            'Data;Czas;Data waluty;Produkt;ISIN;Opis;FX;Zmiana;;Saldo;;Identyfikator zlecenia',
            '15-05-2025;00:00;15-05-2025;ALFA CORP;US000ALFA001;Dywidenda;;USD;1.010,00;USD;5.000,00;',
            '15-05-2025;00:00;15-05-2025;ALFA CORP;US000ALFA001;Podatek od dywidendy;;USD;-151,50;USD;4.848,50;',
            '1010.00',
            '151.50',
        ];

        yield 'french' => [
            'Date,Heure,Date de valeur,Produit,ISIN,Libellé,FX,Mutation,,Solde,,Order Id',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividende,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Impôts sur dividende,,USD,-1.50,USD,498.50,',
            '10.00',
            '1.50',
        ];

        yield 'spanish' => [
            'Fecha,Hora,Fecha valor,Producto,ISIN,Descripción,FX,Variación,,Saldo,,ID Orden',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividendo,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Retención del dividendo,,USD,-1.50,USD,498.50,',
            '10.00',
            '1.50',
        ];

        yield 'german' => [
            'Datum,Zeit,Wertdatum,Produkt,ISIN,Beschreibung,FX,Änderung,,Saldo,,Auftrags-ID',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividende,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Quellensteuer,,USD,-1.50,USD,498.50,',
            '10.00',
            '1.50',
        ];
    }

    /**
     * Older statements name the amount column and put the currency before it.
     */
    public function testReadsTheOlderLayoutWhereTheCurrencyPrecedesTheAmount(): void
    {
        $content = "Date,Time,Value date,Product,ISIN,Description,FX,,Amount,,Balance,Order Id\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-1.50,USD,498.50,\n";

        $result = (new DegiroAccountImporter())->import(new CsvSource('rachunek.csv', $content));

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('10.00', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('1.50', (string) $result->dividends[0]->withheldTax->value());
    }

    /**
     * With the amount in the very first column there is no column before it to
     * hold the currency, so the header is refused instead of every single row.
     */
    public function testAnAmountColumnWithNowhereToPutItsCurrencyIsReportedOnce(): void
    {
        $content = "Amount,Date,Value date,Product,ISIN,Description\n"
            ."10.00,15-05-2025,15-05-2025,ALFA CORP,US000ALFA001,Dividend\n";

        $result = (new DegiroAccountImporter())->import(new CsvSource('rachunek.csv', $content));

        self::assertSame([], $result->dividends);
        self::assertCount(1, $result->errors());
    }

    public function testNonDividendMovementsAreSummarisedNotTreatedAsErrors(): void
    {
        $result = self::import([
            '02-01-2025,10:00,02-01-2025,,,iDEAL Deposit,,EUR,1000.00,EUR,1000.00,',
            '02-01-2025,10:00,02-01-2025,,,DEGIRO Transaction Fee,,EUR,-1.00,EUR,999.00,',
            '31-01-2025,10:00,31-01-2025,,,Flatex Interest,,EUR,-0.50,EUR,998.50,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);

        $infos = implode(' ', $result->infos());
        self::assertStringContainsString('3', $infos);
        self::assertStringContainsString('Pominięto', $infos);
    }

    public function testACurrencyConversionRowIsNotMistakenForADividend(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,,,Currency Exchange,,USD,-2.50,USD,497.50,',
            '15-05-2025,00:00,15-05-2025,,,Valuta Debitering,,EUR,2.30,EUR,2.30,',
        ]);

        self::assertCount(1, $result->dividends);
        self::assertSame('2.50', (string) $result->dividends[0]->grossAmount->value());
    }

    public function testUnrelatedTransactionTaxesAreNotAttachedToADividend(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-1.50,USD,498.50,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Transactiebelasting,,USD,-2.00,USD,496.50,',
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Podatek od transakcji,,USD,-3.00,USD,493.50,',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('1.50', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testTheCountryInferredFromTheIsinIsFlaggedForReview(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,',
        ]);

        $warnings = mb_strtolower(implode(' ', $result->warnings()));
        self::assertStringContainsString('isin', $warnings);
        self::assertStringContainsString('sprawdź', $warnings);
    }

    public function testADividendWithoutAnIsinKeepsTheProductNameAndNoCountry(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,GAMMA SA,,Dividend,,USD,2.50,USD,500.00,',
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('GAMMA SA', $result->dividends[0]->name);
        self::assertSame('', $result->dividends[0]->countryCode);
        self::assertStringContainsString('Kraj', implode(' ', $result->warnings()));
    }

    public function testADividendWithNeitherIsinNorProductIsRefused(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,,,Dividend,,USD,2.50,USD,500.00,',
        ]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testAMalformedAmountIsARowErrorAndNotAnException(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,n/a,USD,500.00,',
        ]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testAMisalignedRowIsRefusedRatherThanReadWithShiftedColumns(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50',
        ]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testTwoInstrumentsPaidOnTheSameDayStayApart(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,',
            '15-05-2025,00:00,15-05-2025,BETA ETF,IE000BETA002,Dividend,,EUR,7.00,EUR,507.00,',
        ]);

        self::assertCount(2, $result->dividends);

        $countries = array_map(static fn ($d): string => $d->countryCode, $result->dividends);
        sort($countries);
        self::assertSame(['IE', 'US'], $countries);
    }

    public function testEveryDividendSaysWhichFileAndFormatItCameFrom(): void
    {
        $result = self::import([
            '15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,',
        ]);

        self::assertStringContainsString('rachunek.csv', $result->dividends[0]->source);
        self::assertStringContainsString('DEGIRO', $result->dividends[0]->source);
    }

    /**
     * DEGIRO books a dividend and its withholding tax as two rows, and a user
     * exporting month by month can easily split them across files. Aggregating
     * per file would then report the tax as zero and overstate the tax due.
     */
    public function testGrossAndTaxAreAggregatedAcrossSeveralStatements(): void
    {
        $result = (new DegiroAccountImporter())->importMany([
            new CsvSource('styczen.csv', self::HEADER_EN."\n"
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,\n"),
            new CsvSource('luty.csv', self::HEADER_EN."\n"
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-1.50,USD,498.50,\n"),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('10.00', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('1.50', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testEachStatementInABatchKeepsItsOwnHeaderAndLocale(): void
    {
        $result = (new DegiroAccountImporter())->importMany([
            new CsvSource('english.csv', self::HEADER_EN."\n"
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,\n"),
            new CsvSource('polish.csv', "Data;Czas;Data waluty;Produkt;ISIN;Opis;FX;Zmiana;;Saldo;;Identyfikator zlecenia\n"
                ."15-05-2025;00:00;15-05-2025;ALFA CORP;US000ALFA001;Podatek od dywidendy;;USD;-1,50;USD;498,50;\n"),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('1.50', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testOverlappingStatementsDoNotDoubleADividend(): void
    {
        $file = self::HEADER_EN."\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-1.50,USD,498.50,\n";

        $result = (new DegiroAccountImporter())->importMany([
            new CsvSource('Account.csv', $file),
            new CsvSource('Account.csv', $file),
        ]);

        self::assertSame([], $result->errors());
        self::assertCount(1, $result->dividends);
        self::assertSame('10.00', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('1.50', (string) $result->dividends[0]->withheldTax->value());
    }

    /**
     * Two payments of the same size on the same day are two payments. Only their
     * position inside one export tells them apart, so an overlap must drop the
     * repeat of the pair without collapsing the pair itself.
     */
    public function testTwoIdenticalPaymentsInOneStatementSurviveAnOverlap(): void
    {
        $file = self::HEADER_EN."\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,510.00,\n";

        $once = (new DegiroAccountImporter())->importMany([new CsvSource('Account.csv', $file)]);
        $twice = (new DegiroAccountImporter())->importMany([
            new CsvSource('Account.csv', $file),
            new CsvSource('Account.csv', $file),
        ]);

        // Both rows belong to one payment key, so they are summed - and the
        // repeated file must not change that sum.
        self::assertSame('20.00', (string) $once->dividends[0]->grossAmount->value());
        self::assertSame('20.00', (string) $twice->dividends[0]->grossAmount->value());
    }

    public function testAnErrorInOneStatementOfABatchIsReported(): void
    {
        $result = (new DegiroAccountImporter())->importMany([
            new CsvSource('good.csv', self::HEADER_EN."\n"
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,\n"),
            new CsvSource('bad.csv', self::HEADER_EN."\n"
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,n/a,USD,500.00,\n"),
        ]);

        self::assertNotEmpty($result->errors());
        self::assertStringContainsString('bad.csv', implode(' ', $result->errors()));
    }

    public function testOrphanTaxAfterEveryStatementIsAnError(): void
    {
        $result = (new DegiroAccountImporter())->importMany([
            new CsvSource('a.csv', self::HEADER_EN."\n"
                ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-1.50,USD,498.50,\n"),
            new CsvSource('b.csv', self::HEADER_EN."\n"
                ."20-06-2025,00:00,20-06-2025,BETA ETF,IE000BETA002,Dividend,,EUR,7.00,EUR,507.00,\n"),
        ]);

        self::assertNotEmpty($result->errors());
        self::assertCount(1, $result->dividends);
        self::assertStringContainsString('nie pasuje', implode(' ', $result->errors()));
    }

    public function testImportOfASingleFileGoesThroughTheSameBatchPath(): void
    {
        $source = new CsvSource('rachunek.csv', self::HEADER_EN."\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,10.00,USD,500.00,\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend Tax,,USD,-1.50,USD,498.50,\n");

        $single = (new DegiroAccountImporter())->import($source);
        $batch = (new DegiroAccountImporter())->importMany([$source]);

        self::assertCount(1, $single->dividends);
        self::assertSame(
            $single->dividends[0]->fingerprint(),
            $batch->dividends[0]->fingerprint(),
        );
    }

    public function testAnEmptyFileIsRejectedWithAMessage(): void
    {
        $result = (new DegiroAccountImporter())->import(new CsvSource('rachunek.csv', ''));

        self::assertNotEmpty($result->errors());
    }

    /**
     * @param list<string> $rows
     */
    private static function import(array $rows): ImportResult
    {
        $content = self::HEADER_EN."\n".implode("\n", $rows)."\n";

        return (new DegiroAccountImporter())->import(new CsvSource('rachunek.csv', $content));
    }
}

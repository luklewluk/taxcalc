<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import;

use App\Import\CsvFormat;
use App\Import\CsvSource;
use App\Import\FormatDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FormatDetector::class)]
final class FormatDetectorTest extends TestCase
{
    #[DataProvider('samples')]
    public function testDetectsSupportedFormats(string $content, CsvFormat $expected): void
    {
        self::assertSame($expected, (new FormatDetector())->detect(new CsvSource('f.csv', $content)));
    }

    /**
     * @return iterable<string, array{string, CsvFormat}>
     */
    public static function samples(): iterable
    {
        yield 'ibkr flat trades' => [
            "\"AssetClass\",\"Symbol\",\"TradeDate\",\"Quantity\",\"TradePrice\",\"NetCash\",\"TransactionID\",\"CurrencyPrimary\"\n"
            ."\"STK\",\"CSPX\",\"20240403\",\"3\",\"507.5782\",\"-1523.98\",\"1\",\"EUR\"\n",
            CsvFormat::IbkrTrades,
        ];

        yield 'ibkr flat activity dividends' => [
            "\"CurrencyPrimary\",\"Symbol\",\"Multiplier\",\"Date/Time\",\"Amount\",\"Type\",\"TransactionID\"\n"
            ."\"USD\",\"VUSD\",\"1\",\"20250402;202000\",\"56.75\",\"Dividends\",\"1\"\n",
            CsvFormat::IbkrActivityDividends,
        ];

        yield 'ibkr sectioned dividend detail' => [
            "Account,Header,AccountNumber,AccountAlias,Name,BaseCurrency,\n"
            ."Account,Data,UXXXXXXXX,,Jan Kowalski,EUR,\n"
            ."DividendDetail,Header,DataDiscriminator,Currency,Symbol,Conid,Country,ReportDate,ExDate,Shares,RevenueComponent,QualifiedIndicator,Gross,GrossInBase,GrossInUSD,Withhold,WithholdInBase,WithholdInUSD\n"
            ."DividendDetail,Data,Summary,USD,AAA,1,US,20221230,20221215,3,,,1.35,1.26,1.35,-0.2,-0.19,-0.2,\n",
            CsvFormat::IbkrDividendDetail,
        ];

        yield 'normalized positions' => [
            "name,country,currency,buy_date,buy_total_amount,sell_date,sell_total_amount\n"
            ."Apple,US,USD,2020-05-04,71.8275,2020-12-16,127.4\n",
            CsvFormat::NormalizedPositions,
        ];

        yield 'normalized dividends' => [
            "name,country,currency,date,amount,tax_paid\n"
            ."Apple,US,USD,2020-08-13,0.82,0.12\n",
            CsvFormat::NormalizedDividends,
        ];

        yield 'degiro transactions, old 16-column english' => [
            "Date,Time,Product,ISIN,Reference Exchange,Execution Venue,Quantity,Price,,Value,,Transaction and/or third,,Total,,Order ID\n"
            ."15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111\n",
            CsvFormat::DegiroTransactions,
        ];

        yield 'degiro transactions, newer english layout' => [
            "Date,Time,Product,ISIN,Reference,Venue,Quantity,Price,,Local value,,Value,,Exchange rate,"
            ."Transaction and/or third party costs,,Total,,Order ID\n"
            ."02-01-2024,09:05,BETA ETF,IE000BETA002,EAM,XAMS,10,264.30,EUR,-2643.00,EUR,-2643.00,EUR,,-2.00,EUR,-2645.00,EUR,bbb-222\n",
            CsvFormat::DegiroTransactions,
        ];

        yield 'degiro transactions, dutch' => [
            "Datum,Tijd,Product,ISIN,Beurs,Uitvoeringsplaats,Aantal,Koers,,Lokale waarde,,Waarde,,Wisselkoers,"
            ."Transactiekosten en/of,,Totaal,,Order ID\n"
            ."15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1.00,USD,-1756.00,USD,aaa-111\n",
            CsvFormat::DegiroTransactions,
        ];

        yield 'degiro transactions, polish' => [
            "Data,Czas,Produkt,ISIN,Giełda referenc,Miejsce wykonania,Liczba,Kurs,,Wartość lokalna,,Wartość,,"
            ."Kurs wymian,Opłata transakcyjna,,Razem,,Identyfikator zlecenia\n"
            ."15-03-2025,09:15,ALFA CORP,US000ALFA001,NDQ,XNAS,10,175.50,USD,-1755.00,USD,-1755.00,USD,,-1.00,USD,-1756.00,USD,aaa-111\n",
            CsvFormat::DegiroTransactions,
        ];

        yield 'degiro transactions, semicolon delimited' => [
            "Data;Czas;Produkt;ISIN;Giełda referenc;Miejsce wykonania;Liczba;Kurs;;Wartość lokalna;;Wartość;;"
            ."Kurs wymian;Opłata transakcyjna;;Razem;;Identyfikator zlecenia\n"
            ."15-03-2025;09:15;ALFA CORP;US000ALFA001;NDQ;XNAS;10;175,50;USD;-1.755,00;USD;-1.755,00;USD;;-1,00;USD;-1.756,00;USD;aaa-111\n",
            CsvFormat::DegiroTransactions,
        ];

        yield 'degiro account statement, english' => [
            "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,\n",
            CsvFormat::DegiroAccount,
        ];

        yield 'degiro account statement, dutch' => [
            "Datum,Tijd,Valutadatum,Product,ISIN,Omschrijving,FX,Mutatie,,Saldo,,Order Id\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,\n",
            CsvFormat::DegiroAccount,
        ];

        yield 'degiro account statement, polish semicolon' => [
            "Data;Czas;Data waluty;Produkt;ISIN;Opis;FX;Zmiana;;Saldo;;Identyfikator zlecenia\n"
            ."15-05-2025;00:00;15-05-2025;ALFA CORP;US000ALFA001;Dywidenda;;USD;2,50;USD;500,00;\n",
            CsvFormat::DegiroAccount,
        ];

        yield 'unknown' => ["foo,bar\n1,2\n", CsvFormat::Unknown];
        yield 'empty' => ['', CsvFormat::Unknown];
        yield 'not a csv at all' => ["just some prose\nwithout structure\n", CsvFormat::Unknown];
    }

    public function testIgnoresUtf8ByteOrderMark(): void
    {
        $content = "\u{FEFF}name,country,currency,date,amount,tax_paid\nApple,US,USD,2020-08-13,0.82,0.12\n";

        self::assertSame(
            CsvFormat::NormalizedDividends,
            (new FormatDetector())->detect(new CsvSource('f.csv', $content)),
        );
    }

    public function testHeaderMatchingIsCaseInsensitiveAndOrderIndependent(): void
    {
        $content = "Country,Name,Currency,Sell_Date,Buy_Date,Sell_Total_Amount,Buy_Total_Amount\n"
            ."US,Apple,USD,2020-12-16,2020-05-04,127.4,71.8275\n";

        self::assertSame(
            CsvFormat::NormalizedPositions,
            (new FormatDetector())->detect(new CsvSource('f.csv', $content)),
        );
    }

    /**
     * The two DEGIRO exports share most of their columns, so the discriminator
     * has to be structural: trades carry a quantity and a price, the account
     * statement carries a value date and a description.
     */
    public function testTheTwoDegiroExportsAreNotConfusedWithEachOther(): void
    {
        $detector = new FormatDetector();

        $transactions = "Date,Time,Product,ISIN,Reference Exchange,Execution Venue,Quantity,Price,,Value,,"
            ."Transaction and/or third,,Total,,Order ID\n";
        $account = "Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n";

        self::assertSame(CsvFormat::DegiroTransactions, $detector->detect(new CsvSource('t.csv', $transactions)));
        self::assertSame(CsvFormat::DegiroAccount, $detector->detect(new CsvSource('a.csv', $account)));
    }

    public function testSemicolonDelimitedFilesAreRecognised(): void
    {
        $content = "name;country;currency;date;amount;tax_paid\nApple;US;USD;2020-08-13;0,82;0,12\n";

        self::assertSame(
            CsvFormat::NormalizedDividends,
            (new FormatDetector())->detect(new CsvSource('f.csv', $content)),
        );
    }
}

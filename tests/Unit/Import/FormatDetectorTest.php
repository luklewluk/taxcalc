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
        yield 'ibkr activity statement' => [
            "\u{FEFF}Statement,Header,Field Name,Field Value\n"
            ."Statement,Data,BrokerName,Interactive Brokers Ireland Limited\n"
            ."Statement,Data,Title,Activity Statement\n"
            ."Statement,Data,Period,\"January 1, 2026 - October 6, 2026\"\n"
            ."Account Information,Header,Field Name,Field Value\n"
            ."Account Information,Data,Account,UXXXXXXXX\n",
            CsvFormat::IbkrActivityStatement,
        ];

        // A statement generated in another language says nothing we can map
        // safely; it is reported as unrecognized rather than half-read.
        yield 'ibkr activity statement, other language' => [
            "Statement,Header,Field Name,Field Value\n"
            ."Statement,Data,Title,Kontoauszug\n",
            CsvFormat::Unknown,
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

        yield 'degiro account statement, polish with two date columns' => [
            "Data,Czas,Data,Produkt,ISIN,Opis,Kurs,Zmiana,,Saldo,,Identyfikator zlecenia\n"
            ."02-01-2025,00:00,29-12-2024,ALFA CORP,US000ALFA001,Dywidenda,,USD,2.50,USD,500.00,\n",
            CsvFormat::DegiroAccount,
        ];

        yield 'unknown' => ["foo,bar\n1,2\n", CsvFormat::Unknown];
        yield 'empty' => ['', CsvFormat::Unknown];
        yield 'not a csv at all' => ["just some prose\nwithout structure\n", CsvFormat::Unknown];
    }

    public function testIgnoresUtf8ByteOrderMark(): void
    {
        // Without stripping, the first column would read "\u{FEFF}date" and
        // the booking-date alias would not match.
        $content = "\u{FEFF}Date,Time,Value date,Product,ISIN,Description,FX,Change,,Balance,,Order Id\n"
            ."15-05-2025,00:00,15-05-2025,ALFA CORP,US000ALFA001,Dividend,,USD,2.50,USD,500.00,\n";

        self::assertSame(
            CsvFormat::DegiroAccount,
            (new FormatDetector())->detect(new CsvSource('f.csv', $content)),
        );
    }

    public function testHeaderMatchingIsCaseInsensitiveAndOrderIndependent(): void
    {
        $content = "ORDER ID,isin,PRICE,,Quantity,TOTAL,,date,Product,TIME\n"
            ."aaa-111,US000ALFA001,175.50,USD,10,-1756.00,USD,15-03-2025,ALFA CORP,09:15\n";

        self::assertSame(
            CsvFormat::DegiroTransactions,
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
        $content = "Date;Time;Value date;Product;ISIN;Description;FX;Change;;Balance;;Order Id\n"
            ."15-05-2025;00:00;15-05-2025;ALFA CORP;US000ALFA001;Dividend;;USD;2,50;USD;500,00;\n";

        self::assertSame(
            CsvFormat::DegiroAccount,
            (new FormatDetector())->detect(new CsvSource('f.csv', $content)),
        );
    }
}

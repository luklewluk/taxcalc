<?php

declare(strict_types=1);

namespace App\Tests\Unit\Import\Importer;

use App\Import\CsvSource;
use App\Import\Importer\IbkrDividendDetailImporter;
use App\Import\ImportResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IbkrDividendDetailImporter::class)]
final class IbkrDividendDetailImporterTest extends TestCase
{
    private const string ACCOUNT_SECTION =
        "Account,Header,AccountNumber,AccountAlias,Name,BaseCurrency,\n"
        ."Account,Data,UXXXXXXXX,,Jan Kowalski,EUR,\n";

    private const string DETAIL_HEADER =
        'DividendDetail,Header,DataDiscriminator,Currency,Symbol,Conid,Country,ReportDate,ExDate,Shares,'
        .'RevenueComponent,QualifiedIndicator,Gross,GrossInBase,GrossInUSD,Withhold,WithholdInBase,WithholdInUSD';

    public function testImportsOnlySummaryRows(): void
    {
        $result = $this->import([
            'DividendDetail,Data,Summary,USD,AAA,1,US,20221230,20221215,3,,,1.35,1.26,1.35,-0.2,-0.19,-0.2,',
            'DividendDetail,Data,RevenueComponent,USD,AAA,1,US,20221230,20221215,,Ordinary Dividend,Qualified,1.35,1.26,1.35,-0.2025,-0.189,-0.2025,',
        ]);

        self::assertCount(1, $result->dividends);

        $dividend = $result->dividends[0];
        self::assertSame('AAA', $dividend->name);
        self::assertSame('US', $dividend->countryCode);
        self::assertSame('USD', $dividend->currency);
        self::assertSame('2022-12-30', $dividend->date->format('Y-m-d'));
        self::assertSame('1.35', (string) $dividend->grossAmount->value());
    }

    public function testWithholdingIsStoredAsAPositiveAmount(): void
    {
        $result = $this->import([
            'DividendDetail,Data,Summary,USD,AAA,1,US,20221230,20221215,3,,,1.35,1.26,1.35,-0.2,-0.19,-0.2,',
        ]);

        self::assertSame('0.2', (string) $result->dividends[0]->withheldTax->value());
        self::assertFalse($result->dividends[0]->withheldTax->isNegative());
    }

    public function testCountryComesFromTheFileSoNoWarningIsNeeded(): void
    {
        $result = $this->import([
            'DividendDetail,Data,Summary,USD,AAA,1,US,20221230,20221215,3,,,1.35,1.26,1.35,-0.2,-0.19,-0.2,',
        ]);

        self::assertSame([], $result->warnings());
    }

    public function testMultipleSummaryRowsAreAllImported(): void
    {
        $result = $this->import([
            'DividendDetail,Data,Summary,USD,AAA,1,US,20221230,20221215,3,,,1.35,1.26,1.35,-0.2,-0.19,-0.2,',
            'DividendDetail,Data,Summary,USD,BBB,2,US,20221208,20221116,10,,,0.34,0.32,0.34,-0.05,-0.05,-0.05,',
            'DividendDetail,Data,Summary,EUR,CCC,3,IE,20220701,20220615,5,,,2.50,2.50,2.60,0,0,0,',
        ]);

        self::assertCount(3, $result->dividends);
        self::assertSame('IE', $result->dividends[2]->countryCode);
        self::assertSame('0', (string) $result->dividends[2]->withheldTax->value());
    }

    public function testOtherSectionsAreIgnored(): void
    {
        $content = self::ACCOUNT_SECTION
            ."Statement,Header,Field Name,Field Value\n"
            ."Statement,Data,Period,January 1, 2022 - December 31, 2022\n"
            .self::DETAIL_HEADER."\n"
            ."DividendDetail,Data,Summary,USD,AAA,1,US,20221230,20221215,3,,,1.35,1.26,1.35,-0.2,-0.19,-0.2,\n";

        $result = (new IbkrDividendDetailImporter())->import(new CsvSource('detail.csv', $content));

        self::assertCount(1, $result->dividends);
        self::assertSame([], $result->errors());
    }

    public function testFileWithoutADividendDetailSectionIsAnError(): void
    {
        $result = (new IbkrDividendDetailImporter())
            ->import(new CsvSource('detail.csv', self::ACCOUNT_SECTION));

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors());
    }

    public function testMalformedRowIsReportedAndTheRestStillImports(): void
    {
        $result = $this->import([
            'DividendDetail,Data,Summary,USD,AAA,1,US,not-a-date,20221215,3,,,1.35,1.26,1.35,-0.2,-0.19,-0.2,',
            'DividendDetail,Data,Summary,USD,BBB,2,US,20221208,20221116,10,,,0.34,0.32,0.34,-0.05,-0.05,-0.05,',
        ]);

        self::assertCount(1, $result->dividends);
        self::assertCount(1, $result->errors());
    }

    public function testAccountHolderNameIsNotCopiedIntoAnyImportedRecord(): void
    {
        $result = $this->import([
            'DividendDetail,Data,Summary,USD,AAA,1,US,20221230,20221215,3,,,1.35,1.26,1.35,-0.2,-0.19,-0.2,',
        ]);

        $serialized = json_encode([
            $result->dividends[0]->name,
            $result->dividends[0]->source,
            $result->messages,
        ]);

        self::assertIsString($serialized);
        self::assertStringNotContainsString('Kowalski', $serialized);
        self::assertStringNotContainsString('UXXXXXXXX', $serialized);
    }

    /**
     * @param list<string> $rows
     */
    private function import(array $rows): ImportResult
    {
        $content = self::ACCOUNT_SECTION.self::DETAIL_HEADER."\n".implode("\n", $rows)."\n";

        return (new IbkrDividendDetailImporter())->import(new CsvSource('detail.csv', $content));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Web\RowFormMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The review form is fully attacker-controlled: it is a plain POST body that
 * anyone can edit. Every rule enforced on import has to be enforced here again.
 */
#[CoversClass(RowFormMapper::class)]
final class RowFormValidationTest extends TestCase
{
    public function testATradeThatMovesNoCashIsRejected(): void
    {
        $result = (new RowFormMapper())->mapTrades([self::tradeRow(['total' => '0'])]);

        self::assertSame([], $result->trades);
        self::assertNotEmpty($result->errors);
    }

    #[DataProvider('badQuantities')]
    public function testTamperedQuantityIsRejected(string $quantity): void
    {
        $result = (new RowFormMapper())->mapTrades([self::tradeRow(['quantity' => $quantity])]);

        self::assertSame([], $result->trades);
        self::assertNotEmpty($result->errors);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badQuantities(): iterable
    {
        yield 'zero' => ['0'];
        yield 'blank' => [''];
        yield 'text' => ['abc'];
    }

    public function testTamperedNegativeDividendGrossIsRejected(): void
    {
        $result = (new RowFormMapper())->mapDividends([self::dividendRow(['gross' => '-100.00'])]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors);
    }

    public function testZeroDividendGrossIsRejected(): void
    {
        $result = (new RowFormMapper())->mapDividends([self::dividendRow(['gross' => '0'])]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors);
    }

    public function testNegativeWithheldTaxIsRejectedInTheGenericForm(): void
    {
        $result = (new RowFormMapper())->mapDividends([self::dividendRow(['tax_paid' => '-15.00'])]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors);
    }

    #[DataProvider('badCountries')]
    public function testDividendCountryMustBeTwoLettersBeforeCalculation(string $country): void
    {
        $dividends = (new RowFormMapper())->mapDividends([self::dividendRow(['country' => $country])]);

        self::assertSame([], $dividends->dividends, 'dividend accepted country '.var_export($country, true));
        self::assertNotEmpty($dividends->errors);
    }

    /**
     * A trade may stay blank - the attention panel asks for it per instrument -
     * but whatever is typed must be a country code.
     */
    #[DataProvider('badCountries')]
    public function testTradeCountryIsBlankOrTwoLetters(string $country): void
    {
        $trades = (new RowFormMapper())->mapTrades([self::tradeRow(['country' => $country])]);

        if ('' === trim($country)) {
            self::assertCount(1, $trades->trades);
            self::assertSame('', $trades->trades[0]->instrument->countryCode ?? null);

            return;
        }

        self::assertSame([], $trades->trades, 'trade accepted country '.var_export($country, true));
        self::assertNotEmpty($trades->errors);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badCountries(): iterable
    {
        yield 'blank' => [''];
        yield 'three letters' => ['USA'];
        yield 'one letter' => ['U'];
        yield 'digits' => ['U1'];
        yield 'punctuation' => ['U-'];
        yield 'whitespace only' => ['  '];
    }

    public function testBlankCountryErrorNamesTheRowAndTheColumn(): void
    {
        $result = (new RowFormMapper())->mapDividends([self::dividendRow(['country' => '', 'name' => 'CSPX'])]);

        self::assertMatchesRegularExpression('/kraj/iu', $result->errors[0]);
        self::assertStringContainsString('CSPX', $result->errors[0]);
    }

    public function testLowercaseCountryIsAcceptedAndNormalised(): void
    {
        $result = (new RowFormMapper())->mapTrades([self::tradeRow(['country' => 'ie'])]);

        self::assertCount(1, $result->trades);
        self::assertSame('IE', $result->trades[0]->instrument->countryCode ?? null);
    }

    public function testSyntacticallyValidUnknownCountryIsAllowedThrough(): void
    {
        $result = (new RowFormMapper())->mapDividends([self::dividendRow(['country' => 'ZZ'])]);

        self::assertCount(1, $result->dividends);
        self::assertSame('ZZ', $result->dividends[0]->countryCode);
        self::assertSame([], $result->errors);
    }

    public function testInvalidRowsAreStillReturnedAsFormRowsSoTheUserDoesNotLoseTheirData(): void
    {
        $mapper = new RowFormMapper();
        $rows = [
            self::tradeRow(['name' => 'GOOD']),
            self::tradeRow(['name' => 'BAD', 'id' => 'trade-2', 'quantity' => 'abc']),
        ];

        $result = $mapper->mapTrades($rows);

        self::assertCount(1, $result->trades);
        self::assertCount(2, $result->rows, 'both rows must come back for re-rendering');
        self::assertSame('BAD', $result->rows[1]['name']);
    }

    public function testRemovedRowsAreNotReturnedForReRendering(): void
    {
        $mapper = new RowFormMapper();
        $rows = [
            self::tradeRow(['name' => 'KEEP']),
            self::tradeRow(['name' => 'DROP', 'id' => 'trade-2', 'remove' => '1']),
        ];

        $result = $mapper->mapTrades($rows);

        self::assertCount(1, $result->trades);
        self::assertCount(1, $result->rows);
        self::assertSame('KEEP', $result->rows[0]['name']);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function tradeRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'trade-1',
            'broker' => 'DEGIRO',
            'pool' => 'US000ALFA001',
            'symbol' => 'US000ALFA001',
            'name' => 'ALFA',
            'country' => 'US',
            'asset' => 'STK',
            'date' => '2024-01-01',
            'time' => '10:00:00',
            'side' => 'BUY',
            'quantity' => '3',
            'currency' => 'USD',
            'total' => '10.00',
            'source' => 'test',
        ];
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function dividendRow(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'AAA',
            'country' => 'US',
            'currency' => 'USD',
            'date' => '2025-04-02',
            'gross' => '100.00',
            'tax_paid' => '15.00',
            'source' => 'test',
        ];
    }
}

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
    #[DataProvider('badPositionAmounts')]
    public function testTamperedPositionAmountsAreRejected(string $buy, string $sell): void
    {
        $result = (new RowFormMapper())->mapPositions([self::positionRow(['buy_amount' => $buy, 'sell_amount' => $sell])]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badPositionAmounts(): iterable
    {
        yield 'negative buy' => ['-10.00', '15.00'];
        yield 'negative sell' => ['10.00', '-15.00'];
        yield 'zero buy' => ['0', '15.00'];
        yield 'zero sell' => ['10.00', '0.00'];
        yield 'both negative' => ['-10.00', '-15.00'];
    }

    #[DataProvider('badQuantities')]
    public function testTamperedQuantityIsRejected(string $quantity): void
    {
        $result = (new RowFormMapper())->mapPositions([self::positionRow(['quantity' => $quantity])]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badQuantities(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-3'];
    }

    public function testEmptyQuantityStaysOptional(): void
    {
        $result = (new RowFormMapper())->mapPositions([self::positionRow(['quantity' => ''])]);

        self::assertCount(1, $result->positions);
        self::assertNull($result->positions[0]->quantity);
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
    public function testCountryMustBeTwoLettersBeforeCalculation(string $country): void
    {
        $positions = (new RowFormMapper())->mapPositions([self::positionRow(['country' => $country])]);
        $dividends = (new RowFormMapper())->mapDividends([self::dividendRow(['country' => $country])]);

        self::assertSame([], $positions->positions, 'position accepted country '.var_export($country, true));
        self::assertSame([], $dividends->dividends, 'dividend accepted country '.var_export($country, true));
        self::assertNotEmpty($positions->errors);
        self::assertNotEmpty($dividends->errors);
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
        $result = (new RowFormMapper())->mapPositions([self::positionRow(['country' => '', 'name' => 'CSPX'])]);

        self::assertMatchesRegularExpression('/kraj/iu', $result->errors[0]);
        self::assertStringContainsString('CSPX', $result->errors[0]);
    }

    public function testLowercaseCountryIsAcceptedAndNormalised(): void
    {
        $result = (new RowFormMapper())->mapPositions([self::positionRow(['country' => 'ie'])]);

        self::assertCount(1, $result->positions);
        self::assertSame('IE', $result->positions[0]->countryCode);
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
            self::positionRow(['name' => 'GOOD']),
            self::positionRow(['name' => 'BAD', 'country' => '']),
        ];

        $result = $mapper->mapPositions($rows);

        self::assertCount(1, $result->positions);
        self::assertCount(2, $result->rows, 'both rows must come back for re-rendering');
        self::assertSame('BAD', $result->rows[1]['name']);
    }

    public function testRemovedRowsAreNotReturnedForReRendering(): void
    {
        $mapper = new RowFormMapper();
        $rows = [
            self::positionRow(['name' => 'KEEP']),
            self::positionRow(['name' => 'DROP', 'remove' => '1']),
        ];

        $result = $mapper->mapPositions($rows);

        self::assertCount(1, $result->positions);
        self::assertCount(1, $result->rows);
        self::assertSame('KEEP', $result->rows[0]['name']);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function positionRow(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'AAA',
            'country' => 'US',
            'currency' => 'USD',
            'buy_date' => '2024-01-01',
            'buy_amount' => '10.00',
            'sell_date' => '2024-06-01',
            'sell_amount' => '15.00',
            'quantity' => '3',
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

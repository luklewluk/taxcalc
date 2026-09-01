<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Money\Amount;
use App\Web\RowFormMapper;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RowFormMapper::class)]
final class RowFormMapperTest extends TestCase
{
    public function testRoundTripsAPositionThroughTheFormRepresentation(): void
    {
        $mapper = new RowFormMapper();

        $original = new ClosedPosition(
            'AAPL',
            'US',
            'USD',
            new DateTimeImmutable('2024-05-04'),
            Amount::of('71.8275', 'USD'),
            new DateTimeImmutable('2024-12-16'),
            Amount::of('127.4', 'USD'),
            null,
            'plik.csv',
        );

        $result = $mapper->mapPositions([$mapper->positionToForm($original)]);

        self::assertSame([], $result->errors);
        self::assertCount(1, $result->positions);
        self::assertSame('AAPL', $result->positions[0]->name);
        self::assertSame('71.8275', (string) $result->positions[0]->buyAmount->value());
        self::assertSame('127.4', (string) $result->positions[0]->sellAmount->value());
        self::assertSame('2024-12-16', $result->positions[0]->sellDate->format('Y-m-d'));
    }

    public function testRoundTripsADividend(): void
    {
        $mapper = new RowFormMapper();

        $original = new Dividend(
            'AAPL',
            'US',
            'USD',
            new DateTimeImmutable('2024-06-10'),
            Amount::of('100.00', 'USD'),
            Amount::of('15.00', 'USD'),
            'plik.csv',
        );

        $result = $mapper->mapDividends([$mapper->dividendToForm($original)]);

        self::assertSame([], $result->errors);
        self::assertSame('100.00', (string) $result->dividends[0]->grossAmount->value());
        self::assertSame('15.00', (string) $result->dividends[0]->withheldTax->value());
    }

    public function testUserEditsAreHonoured(): void
    {
        $result = (new RowFormMapper())->mapPositions([[
            'name' => 'CSPX',
            'country' => 'ie',
            'currency' => 'usd',
            'buy_date' => '2024-06-13',
            'buy_amount' => '2 293,23',
            'sell_date' => '2025-02-27',
            'sell_amount' => '2542.81',
            'quantity' => '3',
            'source' => 'reczne',
        ]]);

        self::assertSame([], $result->errors);
        self::assertSame('IE', $result->positions[0]->countryCode);
        self::assertSame('USD', $result->positions[0]->currency);
        self::assertSame('2293.23', (string) $result->positions[0]->buyAmount->value());
    }

    public function testRowsMarkedForRemovalAreDropped(): void
    {
        $mapper = new RowFormMapper();
        $rows = [
            $mapper->positionToForm(self::position('AAA')),
            ['remove' => '1'] + $mapper->positionToForm(self::position('BBB')),
        ];

        $result = $mapper->mapPositions($rows);

        self::assertCount(1, $result->positions);
        self::assertSame('AAA', $result->positions[0]->name);
    }

    public function testInvalidRowBecomesAnErrorInsteadOfAnException(): void
    {
        $result = (new RowFormMapper())->mapPositions([[
            'name' => 'AAA',
            'country' => 'US',
            'currency' => 'USD',
            'buy_date' => 'nonsense',
            'buy_amount' => '10',
            'sell_date' => '2024-06-01',
            'sell_amount' => '20',
        ]]);

        self::assertSame([], $result->positions);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('1', $result->errors[0]);
    }

    public function testMissingCountryIsRefusedBecauseItDrivesTheCreditAndPitZg(): void
    {
        // Flat IBKR exports carry no country, so blank reaches the review screen -
        // but it must never reach a calculated result.
        $result = (new RowFormMapper())->mapPositions([[
            'name' => 'AAA',
            'country' => '',
            'currency' => 'USD',
            'buy_date' => '2024-01-01',
            'buy_amount' => '10',
            'sell_date' => '2024-06-01',
            'sell_amount' => '20',
        ]]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors);
        self::assertMatchesRegularExpression('/kraj/iu', $result->errors[0]);
    }

    public function testTooManyRowsAreRefusedSoAPostCannotExhaustMemory(): void
    {
        $mapper = new RowFormMapper(maxRows: 3);
        $rows = array_fill(0, 5, $mapper->positionToForm(self::position('AAA')));

        $result = $mapper->mapPositions($rows);

        self::assertCount(3, $result->positions);
        self::assertNotEmpty($result->errors);
    }

    public function testNonArrayInputIsIgnoredRatherThanFatal(): void
    {
        /** @phpstan-ignore-next-line deliberately malformed input */
        $result = (new RowFormMapper())->mapPositions(['not-an-array', 42, null]);

        self::assertSame([], $result->positions);
        self::assertNotEmpty($result->errors);
    }

    private static function position(string $name): ClosedPosition
    {
        return new ClosedPosition(
            $name,
            'US',
            'USD',
            new DateTimeImmutable('2024-01-01'),
            Amount::of('10.00', 'USD'),
            new DateTimeImmutable('2024-06-01'),
            Amount::of('20.00', 'USD'),
            null,
            'test',
        );
    }
}

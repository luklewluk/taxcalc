<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Fifo\InstrumentDetails;
use App\Fifo\Trade;
use App\Model\ClosedPosition;
use App\Model\Dividend;
use App\Money\Amount;
use App\Money\Decimal;
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

    public function testRoundTripsATradeWithAnIndependentExecutionPriceCurrency(): void
    {
        $mapper = new RowFormMapper();
        $trade = new Trade(
            'US000ALFA001',
            new DateTimeImmutable('2024-05-04 09:15:00'),
            Decimal::of('3'),
            Amount::of('1400.00', 'EUR'),
            'order-1',
            'plik.csv',
            new InstrumentDetails('ALFA', 'US'),
            unitPrice: Amount::of('507.578200', 'USD'),
            broker: 'DEGIRO',
            stableId: 'trade-1',
            fifoPool: 'US000ALFA001',
        );

        $form = $mapper->tradeToForm($trade);
        $result = $mapper->mapTrades([$form]);

        self::assertSame([], $result->errors);
        self::assertSame('507.578200', $form['unit_price']);
        self::assertSame('USD', $form['price_currency']);
        self::assertSame('507.578200', (string) $result->trades[0]->unitPrice?->value());
        self::assertSame('USD', $result->trades[0]->unitPrice?->currency());
        self::assertSame('1400.00', (string) $result->trades[0]->grossAmount->value());
    }

    public function testANewTradeRowIsGivenTheIdOfTheTradeItBecame(): void
    {
        $mapper = new RowFormMapper();
        $row = $mapper->tradeToForm(self::trade());
        $row['id'] = '';

        $result = $mapper->mapTrades([$row]);

        self::assertSame([], $result->errors);
        self::assertNotSame('', $result->trades[0]->id());
        self::assertSame($result->trades[0]->id(), $result->rows[0]['id']);
        self::assertSame($result->trades[0]->id(), $mapper->mapTrades($result->rows)->trades[0]->id(), 'The id stays put on the next post.');
    }

    public function testAnInvalidNewTradeRowKeepsItsBlankId(): void
    {
        $mapper = new RowFormMapper();
        $row = $mapper->tradeToForm(self::trade());
        $row['id'] = '';
        $row['quantity'] = 'abc';

        self::assertSame('', $mapper->mapTrades([$row])->rows[0]['id']);
    }

    public function testExecutionPriceAndCurrencyMustBothBePresent(): void
    {
        $row = (new RowFormMapper())->tradeToForm(self::trade());
        $row['unit_price'] = '10.50';
        $row['price_currency'] = '';

        $result = (new RowFormMapper())->mapTrades([$row]);

        self::assertSame([], $result->trades);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('muszą być podane razem', $result->errors[0]);
        self::assertSame('trade.invalid', $result->diagnostics[0]->code);
    }

    public function testExecutionPriceMustBePositiveAndUseAnIsoCurrency(): void
    {
        $mapper = new RowFormMapper();
        $row = $mapper->tradeToForm(self::trade());
        $row['unit_price'] = '0';
        $row['price_currency'] = 'USD';
        self::assertNotEmpty($mapper->mapTrades([$row])->errors);

        $row['unit_price'] = '10';
        $row['price_currency'] = 'DOLLARS';
        self::assertNotEmpty($mapper->mapTrades([$row])->errors);
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
        self::assertSame('position.row_limit', $result->diagnostics[0]->code);
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

    private static function trade(): Trade
    {
        return new Trade(
            'AAA',
            new DateTimeImmutable('2024-01-01'),
            Decimal::of('1'),
            Amount::of('10.00', 'USD'),
            source: 'test',
            instrument: new InstrumentDetails('AAA', 'US'),
            broker: 'IBKR',
            stableId: 'trade-1',
            fifoPool: 'AAA@USD',
        );
    }
}

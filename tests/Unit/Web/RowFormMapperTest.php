<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web;

use App\Fifo\InstrumentDetails;
use App\Fifo\Trade;
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
        $mapper = new RowFormMapper();
        $row = $mapper->tradeToForm(self::trade());
        $row['name'] = 'ALFA (poprawione)';
        $row['country'] = 'ie';
        $row['total'] = '12,50';

        $result = $mapper->mapTrades([$row]);

        self::assertSame([], $result->errors);
        self::assertSame('ALFA (poprawione)', $result->trades[0]->instrument?->displayName);
        self::assertSame('IE', $result->trades[0]->instrument->countryCode ?? null);
        self::assertSame('12.50', (string) $result->trades[0]->grossAmount->value());
    }

    public function testRowsMarkedForRemovalAreDropped(): void
    {
        $mapper = new RowFormMapper();
        $rows = [
            $mapper->tradeToForm(self::trade()),
            ['remove' => '1'] + $mapper->tradeToForm(self::trade()),
        ];

        $result = $mapper->mapTrades($rows);

        self::assertCount(1, $result->trades);
        self::assertCount(1, $result->rows);
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
        $row = (new RowFormMapper())->tradeToForm(self::trade());
        $row['date'] = 'nonsense';

        $result = (new RowFormMapper())->mapTrades([$row]);

        self::assertSame([], $result->trades);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('1', $result->errors[0]);
    }

    public function testMissingDividendCountryIsRefusedBecauseItDrivesTheCredit(): void
    {
        // A dividend needs its country for the treaty cap of the conservative
        // credit; blank may reach the workbench, never a calculated result.
        $result = (new RowFormMapper())->mapDividends([[
            'name' => 'AAA',
            'country' => '',
            'currency' => 'USD',
            'date' => '2025-04-02',
            'gross' => '100.00',
            'tax_paid' => '15.00',
            'source' => 'test',
        ]]);

        self::assertSame([], $result->dividends);
        self::assertNotEmpty($result->errors);
        self::assertMatchesRegularExpression('/kraj/iu', $result->errors[0]);
    }

    public function testTooManyRowsAreRefusedSoAPostCannotExhaustMemory(): void
    {
        $mapper = new RowFormMapper(maxRows: 3);
        $rows = array_fill(0, 5, $mapper->tradeToForm(self::trade()));

        $result = $mapper->mapTrades($rows);

        self::assertCount(3, $result->trades);
        self::assertNotEmpty($result->errors);
        self::assertSame('trade.row_limit', $result->diagnostics[0]->code);
    }

    public function testNonArrayInputIsIgnoredRatherThanFatal(): void
    {
        /** @phpstan-ignore-next-line deliberately malformed input */
        $result = (new RowFormMapper())->mapTrades(['not-an-array', 42, null]);

        self::assertSame([], $result->trades);
        self::assertNotEmpty($result->errors);
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

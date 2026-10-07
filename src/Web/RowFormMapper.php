<?php

declare(strict_types=1);

namespace App\Web;

use App\Exception\InvalidCurrencyException;
use App\Exception\InvalidDateException;
use App\Exception\InvalidNumberException;
use App\Exception\InvalidRecordException;
use App\Import\Parser\DateParser;
use App\Import\Parser\NumberParser;
use App\Model\ClosedPosition;
use App\Model\CountryCode;
use App\Model\Dividend;
use App\Model\AccountFee;
use App\Fifo\Trade;
use App\Fifo\InstrumentDetails;
use App\Fifo\InstrumentKind;
use App\Fifo\PositionEffect;
use App\Money\Amount;

/**
 * Translates between the normalized domain records and the flat string arrays
 * that travel in the review form.
 *
 * The review screen is what keeps the application stateless: instead of storing
 * an import server-side, every row is round-tripped through the form, so the
 * user's financial data lives only in their own browser and in the body of the
 * request they choose to send.
 *
 * Nothing here throws on bad input - a mistyped date must produce a message
 * next to the row, never a 500.
 */
final readonly class RowFormMapper
{
    private const int DEFAULT_MAX_ROWS = 5000;

    public function __construct(private int $maxRows = self::DEFAULT_MAX_ROWS)
    {
    }

    /**
     * @param array<mixed> $rows
     */
    public function mapPositions(array $rows): MappedRows
    {
        $positions = [];
        $errors = [];
        $diagnostics = [];
        $keptRows = [];

        [$rows, $errors] = $this->capRows($rows, $errors);
        foreach ($errors as $message) {
            $diagnostics[] = Diagnostic::blocking('position.row_limit', $message, 'transactions');
        }

        foreach ($rows as $index => $row) {
            $number = $index + 1;

            if (!is_array($row)) {
                $message = sprintf('Pozycja %d: nieprawidłowe dane wiersza.', $number);
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('position.invalid_row', $message, 'transactions');

                continue;
            }

            /** @var array<string, mixed> $row */
            if ($this->isRemoved($row)) {
                continue;
            }

            $keptRows[] = $this->positionRowToForm($row);

            try {
                $currency = strtoupper($this->str($row, 'currency'));
                $name = $this->str($row, 'name');
                if ('' === $name) {
                    $message = sprintf('Pozycja %d: nazwa instrumentu jest wymagana.', $number);
                    $errors[] = $message;
                    $diagnostics[] = Diagnostic::blocking('position.name_missing', $message, 'transactions');

                    continue;
                }

                $buyDate = DateParser::parse($this->str($row, 'buy_date'));
                $sellDate = DateParser::parse($this->str($row, 'sell_date'));

                if ($sellDate < $buyDate) {
                    $message = sprintf(
                        'Pozycja %d (%s): data sprzedaży jest wcześniejsza niż data zakupu.',
                        $number,
                        $name,
                    );
                    $errors[] = $message;
                    $diagnostics[] = Diagnostic::blocking('position.date_order', $message, 'transactions');

                    continue;
                }

                $quantity = $this->str($row, 'quantity');

                $positions[] = new ClosedPosition(
                    $name,
                    CountryCode::normalizeRequired($this->str($row, 'country'), $name, forPitZg: false),
                    $currency,
                    $buyDate,
                    Amount::fromDecimal(NumberParser::parse($this->str($row, 'buy_amount'), decimalComma: true), $currency),
                    $sellDate,
                    Amount::fromDecimal(NumberParser::parse($this->str($row, 'sell_amount'), decimalComma: true), $currency),
                    '' === $quantity ? null : NumberParser::parse($quantity, decimalComma: true),
                    $this->str($row, 'source'),
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $message = sprintf('Pozycja %d: %s', $number, $e->getMessage());
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('position.invalid', $message, 'transactions');
            }
        }

        return new MappedRows($positions, [], $errors, $keptRows, diagnostics: $diagnostics);
    }

    /**
     * @param array<mixed> $rows
     */
    public function mapDividends(array $rows): MappedRows
    {
        $dividends = [];
        $errors = [];
        $diagnostics = [];
        $keptRows = [];

        [$rows, $errors] = $this->capRows($rows, $errors);
        foreach ($errors as $message) {
            $diagnostics[] = Diagnostic::blocking('dividend.row_limit', $message, 'dividends');
        }

        foreach ($rows as $index => $row) {
            $number = $index + 1;

            if (!is_array($row)) {
                $message = sprintf('Dywidenda %d: nieprawidłowe dane wiersza.', $number);
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('dividend.invalid_row', $message, 'dividends');

                continue;
            }

            /** @var array<string, mixed> $row */
            if ($this->isRemoved($row)) {
                continue;
            }

            $keptRows[] = $this->dividendRowToForm($row);

            try {
                $currency = strtoupper($this->str($row, 'currency'));
                $name = $this->str($row, 'name');
                if ('' === $name) {
                    $message = sprintf('Dywidenda %d: nazwa instrumentu jest wymagana.', $number);
                    $errors[] = $message;
                    $diagnostics[] = Diagnostic::blocking('dividend.name_missing', $message, 'dividends', self::rowId($row));

                    continue;
                }

                $dividends[] = new Dividend(
                    $name,
                    CountryCode::normalizeRequired($this->str($row, 'country'), $name),
                    $currency,
                    DateParser::parse($this->str($row, 'date')),
                    Amount::fromDecimal(NumberParser::parse($this->str($row, 'gross'), decimalComma: true), $currency),
                    Amount::fromDecimal(
                        NumberParser::parseOrZero($this->str($row, 'tax_paid'), decimalComma: true),
                        $currency,
                    ),
                    $this->str($row, 'source'),
                    $this->str($row, 'id'),
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $message = sprintf('Dywidenda %d: %s', $number, $e->getMessage());
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('dividend.invalid', $message, 'dividends', self::rowId($row));
            }
        }

        return new MappedRows([], $dividends, $errors, $keptRows, diagnostics: $diagnostics);
    }

    /** @param array<mixed> $rows */
    public function mapTrades(array $rows): MappedRows
    {
        $trades = [];
        $errors = [];
        $diagnostics = [];
        $keptRows = [];
        [$rows, $errors] = $this->capRows($rows, $errors);
        foreach ($errors as $message) {
            $diagnostics[] = Diagnostic::blocking('trade.row_limit', $message, 'transactions');
        }

        foreach ($rows as $index => $row) {
            $number = $index + 1;
            if (!is_array($row)) {
                $message = sprintf('Transakcja %d: nieprawidłowe dane wiersza.', $number);
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('trade.invalid_row', $message, 'transactions');
                continue;
            }
            /** @var array<string, mixed> $row */
            if ($this->isRemoved($row)) {
                continue;
            }
            $form = $this->tradeRowToForm($row);
            $keptRows[] = $form;

            try {
                $broker = $this->str($row, 'broker');
                $symbol = $this->str($row, 'symbol');
                $name = $this->str($row, 'name');
                $currency = strtoupper($this->str($row, 'currency'));
                $country = CountryCode::normalizeOptional($this->str($row, 'country'));
                if ('' === $broker || '' === $symbol || '' === $name) {
                    throw new InvalidRecordException('Broker/pula FIFO, symbol i nazwa instrumentu są wymagane.');
                }
                $side = strtoupper($this->str($row, 'side'));
                if (!in_array($side, ['BUY', 'SELL'], true)) {
                    throw new InvalidRecordException('Kierunek musi mieć wartość BUY albo SELL.');
                }
                $quantity = NumberParser::parse($this->str($row, 'quantity'), decimalComma: true)->abs();
                if (!$quantity->isPositive()) {
                    throw InvalidRecordException::quantityMustBePositive((string) $quantity);
                }
                if ('SELL' === $side) {
                    $quantity = $quantity->negated();
                }

                $date = DateParser::parseWithTime($this->str($row, 'date'), $this->str($row, 'time'));
                $kind = InstrumentKind::fromForm($this->str($row, 'asset'));
                $effect = null;
                if (InstrumentKind::Option === $kind) {
                    $effect = PositionEffect::fromForm($this->str($row, 'effect'))
                        ?? throw new InvalidRecordException('Dla opcji wskaż, czy transakcja otwiera, czy zamyka pozycję.');
                }

                $total = Amount::fromDecimal(
                    NumberParser::parse($this->str($row, 'total'), decimalComma: true)->abs(),
                    $currency,
                );
                $commission = $this->optionalAmount($row, 'commission', $currency);
                $autoFx = $this->optionalAmount($row, 'autofx', $currency);

                if (!$total->isPositive()) {
                    // An option expires or is assigned at nothing - the only
                    // trade that may move no cash, and then it costs no fee.
                    if (InstrumentKind::Option !== $kind) {
                        throw InvalidRecordException::amountMustBePositive('Total/NetCash', $total);
                    }

                    if (PositionEffect::Close !== $effect) {
                        throw new InvalidRecordException(
                            'Kwota Total może być zerowa tylko przy zamknięciu opcji (wygaśnięcie, przydział, wykonanie).',
                        );
                    }

                    foreach ([$commission, $autoFx] as $fee) {
                        if (null !== $fee && !$fee->isZero()) {
                            throw new InvalidRecordException('Zamknięcie opcji kwotą 0 nie może mieć opłaty.');
                        }
                    }
                }

                $unitPrice = $this->optionalExecutionPrice($row);
                $externalId = $this->str($row, 'external_id');

                $trades[] = new Trade(
                    $symbol,
                    $date,
                    $quantity,
                    $total,
                    '' === $externalId ? null : $externalId,
                    $this->str($row, 'source'),
                    new InstrumentDetails($name, $country),
                    externalIdReported: '' !== $externalId,
                    unitPrice: $unitPrice,
                    broker: $broker,
                    commission: $commission,
                    autoFx: $autoFx,
                    stableId: $this->str($row, 'id'),
                    fifoPool: $this->str($row, 'pool') ?: $symbol,
                    kind: $kind,
                    effect: $effect,
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $message = sprintf('Transakcja %d: %s', $number, $e->getMessage());
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('trade.invalid', $message, 'transactions', self::rowId($row));
            }
        }

        return new MappedRows([], [], $errors, $keptRows, $trades, diagnostics: $diagnostics);
    }

    /** @param array<mixed> $rows */
    public function mapFees(array $rows): MappedRows
    {
        $fees = [];
        $errors = [];
        $diagnostics = [];
        $keptRows = [];
        [$rows, $errors] = $this->capRows($rows, $errors);
        foreach ($errors as $message) {
            $diagnostics[] = Diagnostic::blocking('fee.row_limit', $message, 'fees');
        }

        foreach ($rows as $index => $row) {
            $number = $index + 1;
            if (!is_array($row)) {
                $message = sprintf('Opłata %d: nieprawidłowe dane wiersza.', $number);
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('fee.invalid_row', $message, 'fees');
                continue;
            }
            /** @var array<string, mixed> $row */
            if ($this->isRemoved($row)) {
                continue;
            }
            $form = $this->feeRowToForm($row);
            $keptRows[] = $form;

            try {
                $currency = strtoupper($this->str($row, 'currency'));
                $description = $this->str($row, 'description');
                $category = $this->str($row, 'category');
                if ('' === $category) {
                    throw new InvalidRecordException('Kategoria opłaty jest wymagana.');
                }
                $amount = Amount::fromDecimal(
                    NumberParser::parse($this->str($row, 'amount'), decimalComma: true)->abs(),
                    $currency,
                );
                $fees[] = new AccountFee(
                    $description,
                    $category,
                    DateParser::parse($this->str($row, 'value_date')),
                    $currency,
                    $amount,
                    $this->isTruthy($row['correction'] ?? null),
                    $this->str($row, 'source'),
                    $this->str($row, 'id'),
                    $this->isTruthy($row['included'] ?? null),
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $message = sprintf('Opłata %d: %s', $number, $e->getMessage());
                $errors[] = $message;
                $diagnostics[] = Diagnostic::blocking('fee.invalid', $message, 'fees', self::rowId($row));
            }
        }

        return new MappedRows([], [], $errors, $keptRows, [], $fees, $diagnostics);
    }

    /**
     * Echo of a submitted position row, normalized to the form's field names.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function positionRowToForm(array $row): array
    {
        $form = [];
        foreach (['name', 'country', 'currency', 'buy_date', 'buy_amount', 'sell_date', 'sell_amount', 'quantity', 'source'] as $key) {
            $form[$key] = $this->str($row, $key);
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function dividendRowToForm(array $row): array
    {
        $form = [];
        foreach (['id', 'name', 'country', 'currency', 'date', 'gross', 'tax_paid', 'source'] as $key) {
            $form[$key] = $this->str($row, $key);
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function tradeRowToForm(array $row): array
    {
        $form = [];
        foreach (['id', 'broker', 'pool', 'symbol', 'name', 'country', 'exchange', 'asset', 'effect', 'date', 'time', 'side', 'quantity', 'currency', 'total', 'unit_price', 'price_currency', 'commission', 'autofx', 'external_id', 'source'] as $key) {
            $form[$key] = $this->str($row, $key);
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function feeRowToForm(array $row): array
    {
        $form = [];
        foreach (['id', 'description', 'category', 'value_date', 'currency', 'amount', 'correction', 'source', 'included'] as $key) {
            $form[$key] = $this->str($row, $key);
        }

        return $form;
    }

    /**
     * @return array<string, string>
     */
    public function positionToForm(ClosedPosition $position): array
    {
        return [
            'name' => $position->name,
            'country' => $position->countryCode,
            'currency' => $position->currency,
            'buy_date' => $position->buyDate->format('Y-m-d'),
            'buy_amount' => (string) $position->buyAmount->value(),
            'sell_date' => $position->sellDate->format('Y-m-d'),
            'sell_amount' => (string) $position->sellAmount->value(),
            'quantity' => null === $position->quantity ? '' : (string) $position->quantity,
            'source' => $position->source,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function dividendToForm(Dividend $dividend): array
    {
        return [
            'id' => $dividend->id(),
            'name' => $dividend->name,
            'country' => $dividend->countryCode,
            'currency' => $dividend->currency,
            'date' => $dividend->date->format('Y-m-d'),
            'gross' => (string) $dividend->grossAmount->value(),
            'tax_paid' => (string) $dividend->withheldTax->value(),
            'source' => $dividend->source,
        ];
    }

    /** @return array<string, string> */
    public function tradeToForm(Trade $trade): array
    {
        return [
            'id' => $trade->id(),
            'broker' => $trade->broker ?: 'Ręczne',
            'pool' => $trade->fifoPool ?: $trade->symbol,
            'symbol' => $trade->symbol,
            'name' => $trade->instrument?->displayName ?: $trade->symbol,
            'country' => $trade->instrument->countryCode ?? '',
            // Audit-only provenance of the country proposal. It rides the form
            // rather than the domain because the disagreement between the
            // listing venue and the ISIN has to be recomputable on every post -
            // import warnings are dropped by the time the user edits anything.
            'exchange' => $trade->instrument->exchangeCode ?? '',
            'asset' => $trade->kind->value,
            'effect' => $trade->effect->value ?? '',
            'date' => $trade->date->format('Y-m-d'),
            'time' => $trade->date->format('H:i:s'),
            'side' => $trade->isBuy() ? 'BUY' : 'SELL',
            'quantity' => (string) $trade->quantity->abs(),
            'currency' => $trade->grossAmount->currency(),
            'total' => (string) $trade->grossAmount->value(),
            'unit_price' => null === $trade->unitPrice ? '' : (string) $trade->unitPrice->value(),
            'price_currency' => null === $trade->unitPrice ? '' : $trade->unitPrice->currency(),
            'commission' => null === $trade->commission ? '' : (string) $trade->commission->value(),
            'autofx' => null === $trade->autoFx ? '' : (string) $trade->autoFx->value(),
            'external_id' => $trade->externalId ?? '',
            'source' => $trade->source,
        ];
    }

    /** @return array<string, string> */
    public function feeToForm(AccountFee $fee): array
    {
        return [
            'id' => $fee->id(),
            'description' => $fee->description,
            'category' => $fee->category,
            'value_date' => $fee->valueDate->format('Y-m-d'),
            'currency' => $fee->currency,
            'amount' => (string) $fee->amount->value(),
            'correction' => $fee->correction ? '1' : '0',
            'source' => $fee->source,
            'included' => $fee->included ? '1' : '0',
        ];
    }

    /**
     * @param array<mixed>  $rows
     * @param list<string>  $errors
     *
     * @return array{list<mixed>, list<string>}
     */
    private function capRows(array $rows, array $errors): array
    {
        $values = array_values($rows);

        if (count($values) > $this->maxRows) {
            $errors[] = sprintf(
                'Przekroczono limit %d wierszy - uwzględniono tylko pierwsze %d. Podziel dane na mniejsze części.',
                $this->maxRows,
                $this->maxRows,
            );
            $values = array_slice($values, 0, $this->maxRows);
        }

        return [$values, $errors];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isRemoved(array $row): bool
    {
        $remove = $row['remove'] ?? null;

        return is_scalar($remove) && in_array((string) $remove, ['1', 'on', 'true'], true);
    }

    /** @param array<string, mixed> $row */
    private function optionalAmount(array $row, string $key, string $currency): ?Amount
    {
        $raw = $this->str($row, $key);
        if ('' === $raw) {
            return null;
        }

        $value = NumberParser::parse($raw, decimalComma: true);
        if ($value->isNegative()) {
            // These two now feed the declared przychód and koszt, so the user
            // actually reaches this message - it may not leak the form key.
            $label = ['commission' => 'Prowizja', 'autofx' => 'AutoFX'][$key] ?? $key;

            throw new InvalidRecordException(sprintf('%s nie może być ujemne.', $label));
        }

        return Amount::fromDecimal($value, $currency);
    }

    /** @param array<string, mixed> $row */
    private function optionalExecutionPrice(array $row): ?Amount
    {
        $rawPrice = $this->str($row, 'unit_price');
        $currency = strtoupper($this->str($row, 'price_currency'));
        if ('' === $rawPrice && '' === $currency) {
            return null;
        }
        if ('' === $rawPrice || '' === $currency) {
            throw new InvalidRecordException('Cena wykonania i waluta ceny muszą być podane razem.');
        }

        $price = Amount::fromDecimal(NumberParser::parse($rawPrice, decimalComma: true), $currency);
        if (!$price->isPositive()) {
            throw InvalidRecordException::amountMustBePositive('cena wykonania', $price);
        }

        return $price;
    }

    private function isTruthy(mixed $value): bool
    {
        return is_scalar($value) && in_array((string) $value, ['1', 'on', 'true'], true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $row */
    private static function rowId(array $row): ?string
    {
        $id = $row['id'] ?? null;

        return is_scalar($id) && '' !== trim((string) $id) ? trim((string) $id) : null;
    }
}

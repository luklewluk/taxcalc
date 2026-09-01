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
        $keptRows = [];

        [$rows, $errors] = $this->capRows($rows, $errors);

        foreach ($rows as $index => $row) {
            $number = $index + 1;

            if (!is_array($row)) {
                $errors[] = sprintf('Pozycja %d: nieprawidłowe dane wiersza.', $number);

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
                    $errors[] = sprintf('Pozycja %d: nazwa instrumentu jest wymagana.', $number);

                    continue;
                }

                $buyDate = DateParser::parse($this->str($row, 'buy_date'));
                $sellDate = DateParser::parse($this->str($row, 'sell_date'));

                if ($sellDate < $buyDate) {
                    $errors[] = sprintf(
                        'Pozycja %d (%s): data sprzedaży jest wcześniejsza niż data zakupu.',
                        $number,
                        $name,
                    );

                    continue;
                }

                $quantity = $this->str($row, 'quantity');

                $positions[] = new ClosedPosition(
                    $name,
                    CountryCode::normalizeRequired($this->str($row, 'country'), $name),
                    $currency,
                    $buyDate,
                    Amount::fromDecimal(NumberParser::parse($this->str($row, 'buy_amount'), decimalComma: true), $currency),
                    $sellDate,
                    Amount::fromDecimal(NumberParser::parse($this->str($row, 'sell_amount'), decimalComma: true), $currency),
                    '' === $quantity ? null : NumberParser::parse($quantity, decimalComma: true),
                    $this->str($row, 'source'),
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $errors[] = sprintf('Pozycja %d: %s', $number, $e->getMessage());
            }
        }

        return new MappedRows($positions, [], $errors, $keptRows);
    }

    /**
     * @param array<mixed> $rows
     */
    public function mapDividends(array $rows): MappedRows
    {
        $dividends = [];
        $errors = [];
        $keptRows = [];

        [$rows, $errors] = $this->capRows($rows, $errors);

        foreach ($rows as $index => $row) {
            $number = $index + 1;

            if (!is_array($row)) {
                $errors[] = sprintf('Dywidenda %d: nieprawidłowe dane wiersza.', $number);

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
                    $errors[] = sprintf('Dywidenda %d: nazwa instrumentu jest wymagana.', $number);

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
                );
            } catch (InvalidNumberException|InvalidDateException|InvalidCurrencyException|InvalidRecordException $e) {
                $errors[] = sprintf('Dywidenda %d: %s', $number, $e->getMessage());
            }
        }

        return new MappedRows([], $dividends, $errors, $keptRows);
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
        foreach (['name', 'country', 'currency', 'date', 'gross', 'tax_paid', 'source'] as $key) {
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
            'name' => $dividend->name,
            'country' => $dividend->countryCode,
            'currency' => $dividend->currency,
            'date' => $dividend->date->format('Y-m-d'),
            'gross' => (string) $dividend->grossAmount->value(),
            'tax_paid' => (string) $dividend->withheldTax->value(),
            'source' => $dividend->source,
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

    /**
     * @param array<string, mixed> $row
     */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}

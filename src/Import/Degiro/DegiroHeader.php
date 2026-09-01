<?php

declare(strict_types=1);

namespace App\Import\Degiro;

/**
 * The header row of a DEGIRO export, resolved to column positions.
 *
 * DEGIRO exports in the language of the account, and both official CSVs repeat
 * the same blank header several times - the currency of an amount is written in
 * the *unnamed* column next to it. Two consequences shape this class:
 *
 *  - column names are matched against per-language alias lists, never against a
 *    single expected spelling;
 *  - lookups return an *index*, because the rows have to be read positionally.
 *    A header-keyed reader would collapse the duplicated blank headers and hand
 *    back the wrong currency for every amount.
 *
 * Alias lists are deliberately matched in full rather than by prefix: the Polish
 * transaction export has both `Kurs` (price) and `Kurs wymian` (exchange rate),
 * and confusing the two would multiply a cost basis by an FX rate.
 */
final readonly class DegiroHeader
{
    public const array DATE = ['date', 'datum', 'data', 'fecha', 'dato'];

    public const array TIME = ['time', 'tijd', 'czas', 'hora', 'zeit', 'heure'];

    public const array PRODUCT = ['product', 'produkt', 'producto', 'produit', 'prodotto'];

    public const array ISIN = ['isin'];

    public const array QUANTITY = [
        'quantity', 'number', 'aantal', 'anzahl', 'liczba', 'cantidad', 'número', 'numero',
        'quantité', 'quantite', 'quantità', 'quantita', 'stück', 'stuck',
    ];

    public const array PRICE = ['price', 'koers', 'kurs', 'precio', 'prix', 'prezzo', 'preis'];

    /**
     * Base names of the settled cash total. A trailing currency code - the
     * `Total EUR` / `Łącznie EUR` variants - is read off the header itself; see
     * {@see indexOfAmount()}.
     */
    public const array TOTAL = ['total', 'totaal', 'totale', 'razem', 'łącznie', 'lacznie', 'gesamt', 'suma'];

    public const array ORDER_ID = [
        'order id', 'orderid', 'order-id', 'order id.', 'identyfikator zlecenia', 'id zlecenia',
        'id orden', 'id de orden', 'auftrags-id', 'auftragsid', 'numéro d\'ordre', 'numero d\'ordre',
    ];

    /**
     * Transaction fee columns. Only detected, never used for the taxable
     * amount: DEGIRO already folds these into the total, and adding them again
     * would double-count the cost. See {@see \App\Import\Importer\DegiroTransactionsImporter}.
     */
    public const array COSTS = [
        'transaction and/or third', 'transaction and/or third party costs', 'transaction costs',
        'transactiekosten en/of', 'transactiekosten', 'opłata transakcyjna', 'oplata transakcyjna',
        'koszty transakcyjne', 'transaktionskosten', 'costes de transacción', 'costes de transaccion',
    ];

    public const array AUTOFX = [
        'autofx', 'autofx commission', 'autofx costs', 'autofx-kosten', 'prowizja autofx',
        'koszty autofx', 'autofx kosten', 'commission autofx',
    ];

    public const array VALUE_DATE = [
        'value date', 'valuta date', 'valutadatum', 'data waluty', 'data rozliczenia',
        'fecha valor', 'wertdatum', 'date de valeur',
    ];

    public const array DESCRIPTION = [
        'description', 'omschrijving', 'opis', 'descripción', 'descripcion',
        'beschreibung', 'buchungstext', 'libellé', 'libelle',
    ];

    /**
     * The balance-change column. In the account statement it holds the
     * *currency*; the amount sits in the next positional column.
     */
    public const array CHANGE = [
        'change', 'mutatie', 'zmiana', 'variación', 'variacion', 'änderung', 'anderung', 'mutation',
    ];

    /**
     * Older account statements name the amount column instead, with the
     * currency in the column immediately *before* it.
     */
    public const array AMOUNT = ['amount', 'importe', 'kwota', 'bedrag', 'betrag', 'montant'];

    /**
     * @param list<string> $columns normalized (lower-cased, trimmed) column names
     */
    public function __construct(public array $columns)
    {
    }

    /**
     * @param list<string> $line raw header fields
     */
    public static function fromFields(array $line): self
    {
        $columns = [];
        foreach ($line as $field) {
            $columns[] = self::normalize($field);
        }

        return new self($columns);
    }

    public static function normalize(string $value): string
    {
        // Non-breaking spaces show up in exports opened and re-saved in Excel.
        $collapsed = preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($collapsed));
    }

    /**
     * @param list<string> $aliases
     */
    public function indexOf(array $aliases): ?int
    {
        foreach ($this->columns as $index => $column) {
            if ('' !== $column && in_array($column, $aliases, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<string> $aliases
     */
    public function has(array $aliases): bool
    {
        return null !== $this->indexOf($aliases);
    }

    /**
     * Locates an amount column, accepting the variants whose header carries the
     * currency (`Total EUR`, `Łącznie EUR`, `Total (EUR)`).
     *
     * @param list<string> $aliases base names, without any currency suffix
     *
     * @return array{int, string|null}|null column index and the currency named
     *                                      in the header, if any
     */
    public function indexOfAmount(array $aliases): ?array
    {
        $exact = $this->indexOf($aliases);
        if (null !== $exact) {
            return [$exact, null];
        }

        foreach ($this->columns as $index => $column) {
            if (1 !== preg_match('/^(.*?)[\s(]+([a-z]{3})\)?$/', $column, $matches)) {
                continue;
            }

            if (in_array(trim($matches[1]), $aliases, true)) {
                return [$index, mb_strtoupper($matches[2])];
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->columns);
    }
}

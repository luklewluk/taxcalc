<?php

declare(strict_types=1);

namespace App\Web;

use App\Model\CountryCode;

/**
 * Groups the editable rows by instrument, separately per tab, and reports what
 * is wrong with their country of income.
 *
 * Lives outside the controller and outside the row mapper on purpose. The mapper
 * sees one kind of row at a time and never runs for dividends on the import
 * path, so it cannot count a whole instrument; the review needs both row sets on
 * every entry path.
 *
 * The same grouping answers two questions, which is why {@see groups()} is
 * public: the panel asks "what is missing and what may I offer", and the bulk
 * writer asks "which posted rows does this group id name". One implementation
 * means the button can never write to a different set than the one it counted.
 *
 * What it deliberately does **not** report: the listing exchange disagreeing
 * with the ISIN registration country. Both are accepted readings of where the
 * income from a disposal arose, so that is a setting
 * ({@see CountrySource}), not a finding - and a dividend's country following
 * the payer's residence while the trades follow the exchange is the correct
 * outcome, not a conflict.
 */
final class CountryReview
{
    /**
     * @param array<int|string, mixed> $tradeRows
     * @param array<int|string, mixed> $dividendRows
     */
    public function inspect(array $tradeRows, array $dividendRows): CountryReviewResult
    {
        $groups = $this->groups($tradeRows, $dividendRows);

        $tradeGroups = [];
        $dividendGroups = [];
        $diagnostics = [];
        $actions = [];

        foreach ($groups as $group) {
            $rows = CountryScope::Trades === $group->scope ? $tradeRows : $dividendRows;
            $blank = $group->blankCount();
            $target = &$tradeGroups;
            if (CountryScope::Dividends === $group->scope) {
                $target = &$dividendGroups;
            }
            foreach ($group->indexes as $index) {
                $target[$index] = ['group_id' => $group->groupId, 'empty_count' => $blank];
            }
            unset($target);

            $conflicting = count($group->countries) > 1;

            if ($blank > 0) {
                $diagnostics[] = Diagnostic::blocking(
                    'country.missing_instrument',
                    self::missingMessage($group, $blank),
                    $group->scope->tab(),
                    $this->rowId($rows[$group->blankIndexes[0]] ?? null),
                    $group->groupId,
                );
            }

            if ($conflicting) {
                $countries = array_keys($group->countries);
                sort($countries);
                $diagnostics[] = Diagnostic::review(
                    'country.conflict_instrument',
                    sprintf(
                        CountryScope::Trades === $group->scope
                            ? 'Instrument %s ma różne kraje w transakcjach: %s. Jedna sprzedaż ma '
                                .'jedno miejsce zbycia, więc ujednolij je przed rozliczeniem.'
                            : 'Instrument %s ma różne kraje w dywidendach: %s. Wypłaty tego samego '
                                .'papieru pochodzą od tego samego wypłacającego, więc ujednolij je.',
                        $group->label,
                        implode(', ', $countries),
                    ),
                    $group->scope->tab(),
                    $this->rowId($rows[$group->indexes[0]] ?? null),
                    $group->groupId,
                );
            }

            if ($blank > 0 || $conflicting) {
                $actions[$group->groupId] = new CountryGroupAction(
                    $group->groupId,
                    $group->label,
                    $blank,
                    $group->rowCount(),
                    $conflicting,
                );
            }
        }

        return new CountryReviewResult($tradeGroups, $dividendGroups, $diagnostics, $actions);
    }

    /**
     * The reason differs by tab, and saying the wrong one sends the user looking
     * for an attachment they must not file: a disposal goes on PIT/ZG, a
     * dividend does not - its country only picks the treaty cap.
     */
    private static function missingMessage(CountryGroup $group, int $blank): string
    {
        if (CountryScope::Trades === $group->scope) {
            return sprintf(
                'Instrument %s: %d transakcj(a/e/i) bez kraju uzyskania dochodu. Kraj jest wymagany '
                .'do rozliczenia i do załącznika PIT/ZG.',
                $group->label,
                $blank,
            );
        }

        return sprintf(
            'Instrument %s: %d dywidend(a/y) bez kraju uzyskania dochodu. Kraj wyznacza limit '
            .'stawki umownej przy odliczeniu podatku pobranego u źródła.',
            $group->label,
            $blank,
        );
    }

    /**
     * @param array<int|string, mixed> $tradeRows
     * @param array<int|string, mixed> $dividendRows
     *
     * @return array<string, CountryGroup> keyed by group id
     */
    public function groups(array $tradeRows, array $dividendRows): array
    {
        $groups = [];
        foreach ([
            [CountryScope::Trades, $tradeRows],
            [CountryScope::Dividends, $dividendRows],
        ] as [$scope, $rows]) {
            foreach ($this->scopeGroups($scope, $rows) as $group) {
                $groups[$group->groupId] = $group;
            }
        }

        return $groups;
    }

    /**
     * @param array<int|string, mixed> $rows
     *
     * @return list<CountryGroup>
     */
    private function scopeGroups(CountryScope $scope, array $rows): array
    {
        /** @var array<string, CountryGroup> $groups keyed by the identity string */
        $groups = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row) || !is_int($index) || $this->isRemoved($row)) {
                continue;
            }

            /** @var array<mixed> $row */
            $name = $this->str($row, 'name');
            $key = CountryScope::Trades === $scope
                ? $this->tradeKey($row)
                : 'name:'.self::normalizeName($name);
            $label = $name;
            if ('' === $label && CountryScope::Trades === $scope) {
                $label = $this->str($row, 'symbol') ?: $this->str($row, 'pool');
            }

            $group = $groups[$key] ??= new CountryGroup(self::groupId($scope, $key), $scope, $label);
            if ('' === $group->label) {
                $group->label = $label;
            }

            $group->indexes[] = $index;
            $this->recordCountry($group, $this->str($row, 'country'), $index);
        }

        return array_values($groups);
    }

    /**
     * The identity string of a trade row: broker plus symbol, upper-cased.
     *
     * The FIFO pool is deliberately not part of it. IBKR keys its pools per
     * currency, and the same paper bought in USD and in EUR still has one
     * country of income.
     *
     * @param array<mixed> $row
     */
    private function tradeKey(array $row): string
    {
        $symbol = $this->str($row, 'symbol');
        if ('' === $symbol) {
            $symbol = $this->str($row, 'pool');
        }

        return 'sym:'.mb_strtoupper($this->str($row, 'broker')).'|'.mb_strtoupper($symbol);
    }

    /**
     * Derived from the content, never from a row id: an id-based group would move
     * every time a row was filled, and the panel could not restore a staged
     * choice across a re-render. The scope is part of it so the two tabs of one
     * instrument address two different actions.
     */
    private static function groupId(CountryScope $scope, string $key): string
    {
        return 'instr-'.substr(hash('sha256', $scope->value.'|'.$key), 0, 16);
    }

    /**
     * Names differ in whitespace between exports far more often than in
     * letters, and DEGIRO pads product names with non-breaking spaces.
     */
    private static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $name)));
    }

    private function recordCountry(CountryGroup $group, string $country, int $index): void
    {
        $country = mb_strtoupper(trim($country));

        if ('' === $country) {
            $group->blankIndexes[] = $index;

            return;
        }

        if (!CountryCode::isValid($country)) {
            // A malformed code is neither a country nor a gap: the row mapper
            // rejects it on its own, and counting it here would double-report.
            return;
        }

        $group->countries[$country] = true;
    }

    private function rowId(mixed $row): ?string
    {
        if (!is_array($row)) {
            return null;
        }

        $id = $this->str($row, 'id');

        return '' === $id ? null : $id;
    }

    /** @param array<mixed> $row */
    private function isRemoved(array $row): bool
    {
        $value = $row['remove'] ?? null;

        return is_scalar($value) && in_array(strtolower(trim((string) $value)), ['1', 'on', 'true'], true);
    }

    /** @param array<mixed> $row */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}

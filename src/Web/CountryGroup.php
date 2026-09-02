<?php

declare(strict_types=1);

namespace App\Web;

/**
 * Every editable row of one instrument **within one tab**.
 *
 * Trades and dividends are deliberately never in the same group. They answer
 * different legal questions: a dividend's country is the residence of the payer,
 * because that is what caps the treaty credit, while a trade's is the place of
 * disposal. A Canadian issuer listed in the US legitimately carries CA on the
 * dividend and US on the trades, so a shared group would both report a false
 * conflict and offer a button that writes the wrong value into one tab.
 *
 * Within the trades, a FIFO pool is not the unit: one instrument can hold two
 * pools of the same broker (IBKR keys them per currency). The broker stays part
 * of the identity - one broker's ticker says nothing about another's - while the
 * currency does not.
 */
final class CountryGroup
{
    /** @var list<int> */
    public array $indexes = [];

    /** @var list<int> */
    public array $blankIndexes = [];

    /**
     * Distinct well-formed countries found in the group, as keys.
     *
     * @var array<string, true>
     */
    public array $countries = [];

    public function __construct(
        public readonly string $groupId,
        public readonly CountryScope $scope,
        public string $label,
    ) {
    }

    public function blankCount(): int
    {
        return count($this->blankIndexes);
    }

    public function rowCount(): int
    {
        return count($this->indexes);
    }
}

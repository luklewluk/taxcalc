<?php

declare(strict_types=1);

namespace App\Import\Ibkr;

/**
 * One `Data` row of an Activity Statement section, keyed by the lower-cased
 * column names of the `Header` row that was in force when it was read.
 */
final readonly class ActivityStatementRow
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        public int $line,
        public array $values,
    ) {
    }

    public function get(string $column): string
    {
        return $this->values[$column] ?? '';
    }
}

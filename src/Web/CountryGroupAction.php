<?php

declare(strict_types=1);

namespace App\Web;

/**
 * What the "Wymaga uwagi" panel needs in order to offer one country control for
 * a whole instrument: which group it writes to, what to call it, and how many
 * rows the label may promise.
 */
final readonly class CountryGroupAction
{
    public function __construct(
        public string $groupId,
        public string $label,
        /** Rows of this instrument with no country at all. */
        public int $blankCount,
        /** Every row of this instrument, blank or not. */
        public int $rowCount,
        /** Whether the rows disagree, which is what makes overwriting legitimate. */
        public bool $conflicting,
    ) {
    }
}

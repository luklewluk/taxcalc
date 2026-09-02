<?php

declare(strict_types=1);

namespace App\Web;

/**
 * The country review of one workbench state: what each row belongs to, what is
 * wrong, and which bulk actions the panel may offer.
 */
final readonly class CountryReviewResult
{
    /**
     * @param array<int, array{group_id: string, empty_count: int}> $tradeGroups    per trade row index
     * @param array<int, array{group_id: string, empty_count: int}> $dividendGroups per dividend row index
     * @param list<Diagnostic>                                     $diagnostics
     * @param array<string, CountryGroupAction>                    $actions        keyed by group id
     */
    public function __construct(
        public array $tradeGroups,
        public array $dividendGroups,
        public array $diagnostics,
        public array $actions,
    ) {
    }
}

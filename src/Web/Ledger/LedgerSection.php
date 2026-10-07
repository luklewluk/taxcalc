<?php

declare(strict_types=1);

namespace App\Web\Ledger;

final readonly class LedgerSection
{
    public const string STOCKS = 'stocks';
    public const string OPTIONS = 'options';

    /**
     * @param list<InstrumentGroup> $groups
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $groups,
    ) {
    }
}

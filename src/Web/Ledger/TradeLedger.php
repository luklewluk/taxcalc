<?php

declare(strict_types=1);

namespace App\Web\Ledger;

/**
 * The Transakcje tab: stocks and options apart, one group per FIFO queue,
 * every trade with what FIFO made of it.
 */
final readonly class TradeLedger
{
    /** @var array<string, LedgerEntry> */
    private array $byId;

    /**
     * @param list<LedgerSection> $sections
     */
    public function __construct(
        public array $sections,
        /** FIFO ran, so the details exist. */
        public bool $fifoAvailable,
        /** Some lots have no PLN figures yet - a later render may fetch them. */
        public bool $ratesIncomplete,
    ) {
        $byId = [];
        foreach ($this->entries() as $entry) {
            if ('' !== $entry->id) {
                $byId[$entry->id] = $entry;
            }
        }
        $this->byId = $byId;
    }

    /** @return list<LedgerEntry> in display order */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->sections as $section) {
            foreach ($section->groups as $group) {
                $entries = [...$entries, ...$group->entries];
            }
        }

        return $entries;
    }

    public function entry(string $id): ?LedgerEntry
    {
        return $this->byId[$id] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->sections;
    }
}

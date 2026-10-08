<?php

declare(strict_types=1);

namespace App\Fifo;

use LogicException;

/**
 * The sales the user tied to named lots, keyed by the sale's trade id. A sale
 * without an entry is matched FIFO.
 */
final readonly class LotAssignments
{
    /**
     * @param array<string, list<LotAllocation>> $bySale
     */
    public function __construct(private array $bySale = [])
    {
        foreach ($bySale as $allocations) {
            $lots = [];
            foreach ($allocations as $allocation) {
                if (isset($lots[$allocation->buyTradeId])) {
                    throw new LogicException('A sale names each lot at most once.');
                }
                $lots[$allocation->buyTradeId] = true;
            }
        }
    }

    /**
     * @return list<LotAllocation>|null
     */
    public function for(string $saleId): ?array
    {
        return $this->bySale[$saleId] ?? null;
    }

    public function has(string $saleId): bool
    {
        return isset($this->bySale[$saleId]);
    }

    /**
     * @param list<LotAllocation> $allocations
     */
    public function with(string $saleId, array $allocations): self
    {
        $bySale = $this->bySale;
        $bySale[$saleId] = $allocations;

        return new self($bySale);
    }

    public function without(string $saleId): self
    {
        $bySale = $this->bySale;
        unset($bySale[$saleId]);

        return new self($bySale);
    }

    /**
     * @param array<string, true> $saleIds
     */
    public function only(array $saleIds): self
    {
        return new self(array_intersect_key($this->bySale, $saleIds));
    }

    /**
     * @return list<string>
     */
    public function saleIds(): array
    {
        return array_map(strval(...), array_keys($this->bySale));
    }

    /**
     * @return array<string, list<LotAllocation>>
     */
    public function all(): array
    {
        return $this->bySale;
    }

    public function isEmpty(): bool
    {
        return [] === $this->bySale;
    }

    public function count(): int
    {
        return count($this->bySale);
    }
}

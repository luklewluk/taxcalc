<?php

declare(strict_types=1);

namespace App\Import\Ibkr;

/**
 * The sections of one Activity Statement that the importer asked for.
 */
final readonly class ActivityStatement
{
    /**
     * @param array<string, list<ActivityStatementRow>> $sections
     */
    public function __construct(private array $sections)
    {
    }

    public function has(string $section): bool
    {
        return [] !== ($this->sections[$section] ?? []);
    }

    /**
     * @return list<ActivityStatementRow>
     */
    public function rows(string $section): array
    {
        return $this->sections[$section] ?? [];
    }
}

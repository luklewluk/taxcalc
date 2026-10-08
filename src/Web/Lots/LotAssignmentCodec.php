<?php

declare(strict_types=1);

namespace App\Web\Lots;

use App\Fifo\LotAllocation;
use App\Fifo\LotAssignments;
use App\Money\Decimal;
use JsonException;

/**
 * The named lots as one hidden form field - never a field per trade row, which
 * would eat into max_input_vars next to 5,000 rows of 21 fields each.
 *
 *   {"v":1,"a":[["<sale id>",[["<lot id>","<quantity>"],...]],...]}
 *
 * Lists rather than objects, so a numeric-looking id never turns into an
 * integer key and duplicates stay detectable. Decoding is total: the field is
 * posted like everything else, so anything malformed comes back as a finding.
 */
final readonly class LotAssignmentCodec
{
    private const int MAX_BYTES = 1_048_576;

    /** A plain decimal: `Decimal::of()` would also take an exponent like 1e999999999. */
    private const string QUANTITY = '/^\d{1,15}(\.\d{1,10})?$/';

    public function __construct(private int $maxRows = 5000)
    {
    }

    public function encode(LotAssignments $assignments): string
    {
        if ($assignments->isEmpty()) {
            return '';
        }

        $list = [];
        foreach ($assignments->all() as $saleId => $allocations) {
            $list[] = [
                (string) $saleId,
                array_map(
                    static fn (LotAllocation $allocation): array => [$allocation->buyTradeId, (string) $allocation->quantity],
                    $allocations,
                ),
            ];
        }

        return json_encode(['v' => 1, 'a' => $list], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function decode(mixed $raw): LotAssignmentsDecoded
    {
        if (null === $raw || '' === $raw) {
            return new LotAssignmentsDecoded(new LotAssignments(), true);
        }

        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) {
            return self::invalid();
        }

        try {
            $data = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::invalid();
        }

        if (!is_array($data) || 1 !== ($data['v'] ?? null) || !is_array($data['a'] ?? null) || !array_is_list($data['a'])) {
            return self::invalid();
        }
        if (count($data['a']) > $this->maxRows) {
            return self::invalid();
        }

        $bySale = [];
        foreach ($data['a'] as $entry) {
            if (!is_array($entry) || !array_is_list($entry) || 2 !== count($entry)) {
                return self::invalid();
            }
            [$saleId, $lots] = $entry;
            if (!self::isId($saleId) || isset($bySale[$saleId]) || !is_array($lots) || !array_is_list($lots)
                || [] === $lots || count($lots) > $this->maxRows) {
                return self::invalid();
            }

            $allocations = [];
            $seen = [];
            foreach ($lots as $lot) {
                if (!is_array($lot) || !array_is_list($lot) || 2 !== count($lot)) {
                    return self::invalid();
                }
                [$lotId, $quantity] = $lot;
                if (!self::isId($lotId) || isset($seen[$lotId]) || !is_string($quantity) || 1 !== preg_match(self::QUANTITY, $quantity)) {
                    return self::invalid();
                }
                $decimal = Decimal::of($quantity);
                if (!$decimal->isPositive()) {
                    return self::invalid();
                }
                $seen[$lotId] = true;
                $allocations[] = new LotAllocation($lotId, $decimal);
            }

            $bySale[$saleId] = $allocations;
        }

        return new LotAssignmentsDecoded(new LotAssignments($bySale), true);
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function isId(mixed $value): bool
    {
        return is_string($value) && '' !== $value && strlen($value) <= 128 && 1 !== preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    private static function invalid(): LotAssignmentsDecoded
    {
        return new LotAssignmentsDecoded(
            new LotAssignments(),
            false,
            'Nie udało się odczytać wskazanych partii z formularza (pole zostało zmienione albo uszkodzone). '
            .'Nic nie zostało przeliczone - przywróć FIFO wszędzie w zakładce FIFO.',
        );
    }
}

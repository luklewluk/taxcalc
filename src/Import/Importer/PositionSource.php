<?php

declare(strict_types=1);

namespace App\Import\Importer;

use App\Import\CsvFormat;

/**
 * Builds the "where did this figure come from" label carried by every closed
 * position.
 *
 * Matching pools all uploaded files, so the buy and the sell leg of one position
 * routinely come from different statements; both file names are then shown so a
 * user can trace the number back to the document it came from.
 */
final class PositionSource
{
    public static function describe(string $buySource, string $sellSource, CsvFormat $format): string
    {
        $label = $format->label();

        if ($buySource === $sellSource || '' === $sellSource) {
            return sprintf('%s (%s)', $buySource, $label);
        }

        if ('' === $buySource) {
            return sprintf('%s (%s)', $sellSource, $label);
        }

        return sprintf('%s → %s (%s)', $buySource, $sellSource, $label);
    }
}

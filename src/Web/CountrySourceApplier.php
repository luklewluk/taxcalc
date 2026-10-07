<?php

declare(strict_types=1);

namespace App\Web;

use App\Import\Degiro\ExchangeCountry;
use App\Import\Degiro\Isin;

/**
 * Re-derives the country *proposal* on trade rows after the user changes which
 * source they want it from.
 *
 * Lives in the web layer rather than in the importers on purpose. Importers
 * always propose the listing-exchange country and carry the venue code along in
 * `trades[N][exchange]`; that plus the ISIN (the symbol for DEGIRO, the pool for
 * the IBKR Activity Statement) is everything needed
 * to compute either reading, so the setting stays reversible without a
 * re-import. Note that {@see RowFormMapper::formToTrade()} rebuilds `Trade`
 * without the venue code, so the form row - not the domain object - is the only
 * place the exchange survives a post.
 *
 * A value matching neither proposal was typed by hand and is never touched. The
 * accepted cost of deriving provenance instead of storing it: a manual answer
 * that happens to equal the other source's proposal is treated as a proposal.
 */
final readonly class CountrySourceApplier
{
    /**
     * @param array<mixed> $tradeRows modified in place
     */
    public function apply(array &$tradeRows, CountrySource $source): void
    {
        foreach ($tradeRows as $index => $row) {
            if (!is_array($row) || self::isRemoved($row)) {
                continue;
            }

            /** @var array<mixed> $row */
            $fromExchange = ExchangeCountry::country(self::str($row, 'exchange'));
            $fromIsin = Isin::country(self::isin($row));

            [$wanted, $other] = CountrySource::Isin === $source
                ? [$fromIsin, $fromExchange]
                : [$fromExchange, $fromIsin];

            if ('' === $wanted) {
                // Nothing to switch to - a multi-venue code or a non-country
                // ISIN prefix. Wiping what is there would lose information.
                continue;
            }

            $current = mb_strtoupper(self::str($row, 'country'));
            if ('' !== $current && $current !== $other) {
                continue;
            }

            $row['country'] = $wanted;
            $tradeRows[$index] = $row;
        }
    }

    /**
     * The ISIN a row is keyed on, wherever its format keeps it: DEGIRO uses it
     * as the symbol, the IBKR Activity Statement as the `ISIN@CURRENCY` pool
     * next to a ticker symbol.
     *
     * @param array<mixed> $row
     */
    private static function isin(array $row): string
    {
        $pool = mb_strtoupper(self::str($row, 'pool'));
        $candidates = [mb_strtoupper(self::str($row, 'symbol')), $pool, explode('@', $pool, 2)[0]];

        foreach ($candidates as $candidate) {
            if (Isin::isWellFormed($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /** @param array<mixed> $row */
    private static function isRemoved(array $row): bool
    {
        $value = $row['remove'] ?? null;

        return is_scalar($value) && in_array(strtolower(trim((string) $value)), ['1', 'on', 'true'], true);
    }

    /** @param array<mixed> $row */
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : '';
    }
}

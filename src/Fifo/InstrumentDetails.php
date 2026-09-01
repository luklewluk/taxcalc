<?php

declare(strict_types=1);

namespace App\Fifo;

/**
 * What a trade's instrument is *called*, as opposed to how it is *identified*.
 *
 * FIFO identity stays {@see Trade::$symbol}. These fields never take part in
 * matching, which is what lets an importer key the queue on something stable
 * (DEGIRO uses the ISIN) while the review screen still shows the product name
 * the broker printed - and lets a company rename itself between the buy and the
 * sell without splitting the position in two.
 *
 * The country is optional and, where a source only lets it be inferred, a
 * proposal for the user to confirm rather than a fact.
 */
final readonly class InstrumentDetails
{
    public function __construct(
        public string $displayName,
        public string $countryCode = '',
    ) {
    }
}

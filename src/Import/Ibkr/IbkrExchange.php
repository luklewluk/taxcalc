<?php

declare(strict_types=1);

namespace App\Import\Ibkr;

/**
 * Interactive Brokers' own exchange codes, as printed in the `Listing Exch`
 * column of an Activity Statement, translated to ISO 10383 MICs.
 *
 * The translation exists so that one vocabulary reaches the workbench:
 * {@see \App\Import\Degiro\ExchangeCountry} already knows the MICs, and
 * {@see \App\Web\CountrySourceApplier} re-derives the proposed country from the
 * round-tripped exchange code. Keeping IBKR's codes out of that table avoids
 * collisions - IBKR's `TSE` is Toronto, not Tokyo (`TSEJ`).
 *
 * An unknown code maps to nothing; the importer then leaves the country blank
 * and names the code in a warning rather than guessing.
 */
final class IbkrExchange
{
    /**
     * @var array<string, string> IBKR code => MIC
     */
    private const array MICS = [
        // United States.
        'NASDAQ' => 'XNAS',
        'NYSE' => 'XNYS',
        'ARCA' => 'ARCX',
        'AMEX' => 'XASE',
        'BATS' => 'BATS',
        'IEX' => 'IEXG',

        // Canada.
        'TSE' => 'XTSE',
        'VENTURE' => 'XTSX',

        // Europe.
        'LSE' => 'XLON',
        'LSEETF' => 'XLON',
        'AEB' => 'XAMS',
        'ENEXT.BE' => 'XBRU',
        'SBF' => 'XPAR',
        'BVL' => 'XLIS',
        'ISED' => 'XDUB',
        'IBIS' => 'XETR',
        'IBIS2' => 'XETR',
        'FWB' => 'XFRA',
        'FWB2' => 'XFRA',
        'SWB' => 'XSTU',
        'GETTEX' => 'XMUN',
        'TGATE' => 'TGAT',
        'EBS' => 'XSWX',
        'VIRTX' => 'XVTX',
        'BM' => 'XMAD',
        'BVME' => 'XMIL',
        'BVME.ETF' => 'XMIL',
        'VSE' => 'XWBO',
        'SFB' => 'XSTO',
        'CPH' => 'XCSE',
        'HEX' => 'XHEL',
        'OSE' => 'XOSL',
        'WSE' => 'XWAR',
        'PRA' => 'XPRA',

        // Asia-Pacific.
        'TSEJ' => 'XTKS',
        'SEHK' => 'XHKG',
        'SGX' => 'XSES',
        'ASX' => 'XASX',
    ];

    public static function mic(string $code): ?string
    {
        return self::MICS[mb_strtoupper(trim($code))] ?? null;
    }

    /**
     * @return array<string, string> IBKR code => MIC
     */
    public static function all(): array
    {
        return self::MICS;
    }
}

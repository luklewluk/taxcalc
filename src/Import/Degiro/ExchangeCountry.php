<?php

declare(strict_types=1);

namespace App\Import\Degiro;

/**
 * The country a trading venue sits in, looked up from the code a broker prints.
 *
 * DEGIRO transaction exports name the venue twice: `Giełda referencyjna` /
 * `Reference Exchange` carries the broker's own three-letter code for the
 * *listing* exchange, and `Miejsce wykonania` / `Execution Venue` carries the
 * ISO 10383 MIC of the venue the order actually filled on. Both vocabularies
 * live in one table because the importer walks them in that order.
 *
 * Two properties keep this honest:
 *
 *  - every row names the exchange it claims, so the country it asserts can be
 *    reviewed by eye. A bare `code => country` pair cannot, and a `/^[A-Z]{2}$/`
 *    assertion cannot tell `MX` from `XX`;
 *  - a code that is *not* in the table resolves to no country at all, and the
 *    importer reports the code verbatim. The table can therefore never put a
 *    wrong country on a tax return silently - it can only fail to propose one.
 *
 * Pan-European MTFs and off-book venues are rows with a *blank* country rather
 * than missing rows: `CEUX` names no listing country, and letting it fall
 * through to "unknown" would suggest the table is merely incomplete. The
 * distinction is deliberate data.
 *
 * The country of *listing* is not necessarily the country of the *source of
 * income* - a Chinese issuer listed in Hong Kong pays out of China. Callers
 * must present this as a proposal for the user to confirm, exactly like
 * {@see Isin::country()}, and must surface the disagreement between the two.
 */
final class ExchangeCountry
{
    /**
     * Venue code => [ISO 3166-1 alpha-2 country, exchange name].
     *
     * A blank country means "this venue names no listing country".
     *
     * @var array<string, array{string, string}>
     */
    private const array EXCHANGES = [
        // DEGIRO reference-exchange codes.
        'NDQ' => ['US', 'Nasdaq'],
        'NSY' => ['US', 'New York Stock Exchange'],
        'ASE' => ['US', 'NYSE American'],
        'TOR' => ['CA', 'Toronto Stock Exchange'],
        'TSV' => ['CA', 'TSX Venture Exchange'],
        'EAM' => ['NL', 'Euronext Amsterdam'],
        'EBR' => ['BE', 'Euronext Bruksela'],
        'EPA' => ['FR', 'Euronext Paryż'],
        'ELI' => ['PT', 'Euronext Lizbona'],
        'DUB' => ['IE', 'Euronext Dublin'],
        'OSL' => ['NO', 'Euronext Oslo'],
        'MIL' => ['IT', 'Borsa Italiana'],
        'XET' => ['DE', 'Xetra'],
        'TDG' => ['DE', 'Tradegate'],
        'FRA' => ['DE', 'Börse Frankfurt'],
        'SWX' => ['CH', 'SIX Swiss Exchange'],
        'MAD' => ['ES', 'BME Madryt'],
        'VIE' => ['AT', 'Wiener Börse'],
        'HEL' => ['FI', 'Nasdaq Helsinki'],
        'CPH' => ['DK', 'Nasdaq Kopenhaga'],
        'STO' => ['SE', 'Nasdaq Sztokholm'],
        'WSE' => ['PL', 'GPW Warszawa'],
        'PRA' => ['CZ', 'Praska Giełda Papierów Wartościowych'],
        'BUD' => ['HU', 'Giełda w Budapeszcie'],
        'ATH' => ['GR', 'Giełda Ateńska'],
        'IST' => ['TR', 'Borsa Istanbul'],
        'LSE' => ['GB', 'London Stock Exchange'],
        'HKG' => ['HK', 'Hong Kong Exchange'],
        'SGX' => ['SG', 'Singapore Exchange'],
        'ASX' => ['AU', 'Australian Securities Exchange'],
        'TAE' => ['IL', 'Tel Aviv Stock Exchange'],
        'JSE' => ['ZA', 'Johannesburg Stock Exchange'],
        'MEX' => ['MX', 'Bolsa Mexicana de Valores'],

        // ISO 10383 MIC codes.
        'XNAS' => ['US', 'Nasdaq'],
        'XNYS' => ['US', 'New York Stock Exchange'],
        'XASE' => ['US', 'NYSE American'],
        'ARCX' => ['US', 'NYSE Arca'],
        'BATS' => ['US', 'Cboe BZX'],
        'IEXG' => ['US', 'IEX'],
        'XTSE' => ['CA', 'Toronto Stock Exchange'],
        'XTSX' => ['CA', 'TSX Venture Exchange'],
        'XLON' => ['GB', 'London Stock Exchange'],
        'XAMS' => ['NL', 'Euronext Amsterdam'],
        'XBRU' => ['BE', 'Euronext Bruksela'],
        'XPAR' => ['FR', 'Euronext Paryż'],
        'XLIS' => ['PT', 'Euronext Lizbona'],
        'XDUB' => ['IE', 'Euronext Dublin'],
        'XETR' => ['DE', 'Xetra'],
        'XFRA' => ['DE', 'Börse Frankfurt'],
        'XBER' => ['DE', 'Börse Berlin'],
        'XMUN' => ['DE', 'Börse München'],
        'XSTU' => ['DE', 'Börse Stuttgart'],
        'XDUS' => ['DE', 'Börse Düsseldorf'],
        'XHAM' => ['DE', 'Börse Hamburg'],
        'TGAT' => ['DE', 'Tradegate'],
        'XSWX' => ['CH', 'SIX Swiss Exchange'],
        'XVTX' => ['CH', 'SIX Swiss Exchange (blue chips)'],
        'XMAD' => ['ES', 'BME Madryt'],
        'XMIL' => ['IT', 'Borsa Italiana'],
        'XWBO' => ['AT', 'Wiener Börse'],
        'XHEL' => ['FI', 'Nasdaq Helsinki'],
        'XCSE' => ['DK', 'Nasdaq Kopenhaga'],
        'XSTO' => ['SE', 'Nasdaq Sztokholm'],
        'XOSL' => ['NO', 'Euronext Oslo'],
        'XWAR' => ['PL', 'GPW Warszawa'],
        'XPRA' => ['CZ', 'Praska Giełda Papierów Wartościowych'],
        'XBUD' => ['HU', 'Giełda w Budapeszcie'],
        'XATH' => ['GR', 'Giełda Ateńska'],
        'XIST' => ['TR', 'Borsa Istanbul'],
        'XLUX' => ['LU', 'Bourse de Luxembourg'],
        'XTKS' => ['JP', 'Tokyo Stock Exchange'],
        'XHKG' => ['HK', 'Hong Kong Exchange'],
        'XSES' => ['SG', 'Singapore Exchange'],
        'XASX' => ['AU', 'Australian Securities Exchange'],
        'XTAE' => ['IL', 'Tel Aviv Stock Exchange'],
        'XJSE' => ['ZA', 'Johannesburg Stock Exchange'],
        'XMEX' => ['MX', 'Bolsa Mexicana de Valores'],
        'XSHG' => ['CN', 'Shanghai Stock Exchange'],
        'XSHE' => ['CN', 'Shenzhen Stock Exchange'],

        // Venues that name no listing country.
        'AQEU' => ['', 'Aquis Exchange Europe (wiele rynków)'],
        'AQXE' => ['', 'Aquis Exchange (wiele rynków)'],
        'BATE' => ['', 'Cboe Europe BXE (wiele rynków)'],
        'CCXE' => ['', 'Cboe Europe CXE (wiele rynków)'],
        'CEUX' => ['', 'Cboe Europe (wiele rynków)'],
        'TQEX' => ['', 'Turquoise Europe (wiele rynków)'],
        'TRQX' => ['', 'Turquoise (wiele rynków)'],
        'SINT' => ['', 'Internalizacja systematyczna'],
        'XOFF' => ['', 'Obrót pozagiełdowy'],
        'XXXX' => ['', 'Brak rynku'],
    ];

    /**
     * The listing country of a venue code, or '' when the code is unknown or
     * names no single country.
     */
    public static function country(string $code): string
    {
        return self::EXCHANGES[self::normalize($code)][0] ?? '';
    }

    /**
     * The exchange name behind a code, or '' when the code is unknown. A known
     * code always has a name, so a blank name means "not in the table".
     */
    public static function name(string $code): string
    {
        return self::EXCHANGES[self::normalize($code)][1] ?? '';
    }

    public static function isKnown(string $code): bool
    {
        return isset(self::EXCHANGES[self::normalize($code)]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function all(): array
    {
        return self::EXCHANGES;
    }

    /**
     * Every country the table can propose, so the UI can offer each of them as
     * an option even when no treaty rate is configured for it.
     *
     * @return list<string>
     */
    public static function countries(): array
    {
        $countries = [];
        foreach (self::EXCHANGES as [$country]) {
            if ('' !== $country) {
                $countries[$country] = true;
            }
        }

        return array_keys($countries);
    }

    private static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}

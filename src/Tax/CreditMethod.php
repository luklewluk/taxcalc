<?php

declare(strict_types=1);

namespace App\Tax;

/**
 * The two competing readings of how much foreign withholding tax may be
 * deducted from the Polish tax on a dividend.
 *
 * This is a live legal dispute, not a settled rule, so the calculator computes
 * both and labels them rather than quietly picking a side.
 */
enum CreditMethod: string
{
    /**
     * Position taken by the tax administration (KIS individual interpretations):
     * the credit is limited to the rate the double-taxation treaty allows the
     * source state to charge. Anything withheld above that has to be reclaimed
     * abroad, not deducted in Poland.
     */
    case Conservative = 'conservative';

    /**
     * Line of final Supreme Administrative Court judgments - II FSK 1171/22
     * (28 February 2023) and II FSK 1302/22 (24 June 2025) - reading art. 30a
     * ust. 9 of the PIT act as allowing the tax *actually* withheld abroad,
     * limited only by the Polish 19%.
     */
    case Nsa = 'nsa';

    public function label(): string
    {
        return match ($this) {
            self::Conservative => 'Wariant zachowawczy (stanowisko KIS - limit stawką umowną)',
            self::Nsa => 'Wariant wg orzecznictwa NSA (podatek faktycznie pobrany do 19%)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Conservative => 'Do odliczenia przyjmujemy niższą z wartości: podatek faktycznie pobrany, '
                .'stawka wynikająca z umowy o unikaniu podwójnego opodatkowania oraz polski podatek 19%. '
                .'Tak wynik liczy zwykle Krajowa Informacja Skarbowa; nadwyżkę ponad stawkę umowną '
                .'odzyskuje się w kraju źródła.',
            self::Nsa => 'Do odliczenia przyjmujemy podatek faktycznie pobrany za granicą, ograniczony wyłącznie '
                .'polskim podatkiem 19% (art. 30a ust. 9 ustawy o PIT). Tak orzekł NSA w wyrokach '
                .'II FSK 1171/22 (28.02.2023) oraz II FSK 1302/22 (24.06.2025).',
        };
    }
}

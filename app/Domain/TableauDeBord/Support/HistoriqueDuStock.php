<?php

namespace App\Domain\TableauDeBord\Support;

/**
 * Le grand livre du stock rejoué, jour par jour, de chaque magasin (voir
 * StockDuTableauDeBord::historique()) : la valeur HT du stock au coût
 * moyen à la fin de chaque jour où elle change, et, de chaque jour, le
 * chiffre d'affaires HT et le coût des articles suivis en stock vendus,
 * retours déduits, en centimes. Chaque barre d'un graphique par période,
 * du jour à l'année, en lit ce qu'elle couvre (voir valeurA(),
 * ventesEntre() et coutsEntre()), jamais plus d'un magasin que ceux qu'on
 * demande.
 */
final class HistoriqueDuStock
{
    /** @var array<int, list<string>> les jours où la valeur d'un magasin change, triés */
    private array $joursDeValeur = [];

    /** @var array<int, list<int>> la valeur de ce magasin à la fin de chacun de ces jours */
    private array $valeurs = [];

    /** @var array<int, SommesParJour> */
    private array $ventes = [];

    /** @var array<int, SommesParJour> */
    private array $couts = [];

    /**
     * @param  array<int, array<string, int>>  $valeurs  par magasin, la valeur à la fin de chaque jour où elle change
     * @param  array<int, array<string, int>>  $ventes  par magasin, le chiffre d'affaires HT de chaque jour
     * @param  array<int, array<string, int>>  $couts  par magasin, le coût de chaque jour
     */
    public function __construct(array $valeurs, array $ventes, array $couts)
    {
        foreach ($valeurs as $magasin => $parJour) {
            ksort($parJour);
            $this->joursDeValeur[$magasin] = array_map(strval(...), array_keys($parJour));
            $this->valeurs[$magasin] = array_values($parJour);
        }

        foreach ($ventes as $magasin => $parJour) {
            $this->ventes[$magasin] = SommesParJour::deJours($parJour);
        }

        foreach ($couts as $magasin => $parJour) {
            $this->couts[$magasin] = SommesParJour::deJours($parJour);
        }
    }

    /**
     * La valeur HT du stock de ces magasins à la fin de ce jour.
     *
     * @param  list<int>  $magasins
     */
    public function valeurA(array $magasins, string $jour): int
    {
        $total = 0;

        foreach ($magasins as $magasin) {
            $jours = $this->joursDeValeur[$magasin] ?? [];
            $bas = 0;
            $haut = count($jours);

            while ($bas < $haut) {
                $milieu = intdiv($bas + $haut, 2);

                if ($jours[$milieu] <= $jour) {
                    $bas = $milieu + 1;
                } else {
                    $haut = $milieu;
                }
            }

            $total += $this->valeurs[$magasin][$bas - 1] ?? 0;
        }

        return $total;
    }

    /**
     * Le chiffre d'affaires HT des articles suivis en stock vendus de ces
     * magasins, du $du au $au compris, retours déduits.
     *
     * @param  list<int>  $magasins
     */
    public function ventesEntre(array $magasins, string $du, string $au): int
    {
        return array_sum(array_map(fn (int $magasin): int => ($this->ventes[$magasin] ?? null)?->entre($du, $au) ?? 0, $magasins));
    }

    /**
     * Le coût au coût moyen de ces articles, du $du au $au compris.
     *
     * @param  list<int>  $magasins
     */
    public function coutsEntre(array $magasins, string $du, string $au): int
    {
        return array_sum(array_map(fn (int $magasin): int => ($this->couts[$magasin] ?? null)?->entre($du, $au) ?? 0, $magasins));
    }
}

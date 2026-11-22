<?php

namespace App\Domain\TableauDeBord\Support;

use Closure;

/**
 * Des montants en centimes rangés par jour « AAAA-MM-JJ », que toute
 * barre d'un graphique par période (un jour, un mois, un trimestre, une
 * année, ou un cumul depuis le début de l'année) additionne d'un seul
 * calcul, sans relire les documents : les jours sont triés une fois, avec
 * leurs sommes cumulées, et chaque question (voir entre()) est une
 * recherche dans cette liste. Les sommes se font ici, en centimes, jamais
 * par une somme de la base sur une colonne décimale.
 *
 * @phpstan-type Ligne array{magasin: int, jour: string}
 */
final class SommesParJour
{
    /** @var list<string> */
    private readonly array $jours;

    /** @var list<int> la somme des premiers jours, de 0 à tous */
    private readonly array $cumuls;

    /**
     * @param  array<string, int>  $parJour
     */
    private function __construct(array $parJour)
    {
        ksort($parJour);
        $jours = [];
        $cumuls = [0];

        foreach ($parJour as $jour => $montant) {
            $jours[] = (string) $jour;
            $cumuls[] = $cumuls[count($cumuls) - 1] + $montant;
        }

        $this->jours = $jours;
        $this->cumuls = $cumuls;
    }

    /**
     * Les montants de ces lignes de ces magasins, rangés par jour ; le jour
     * d'une ligne est celui de son magasin.
     *
     * @template T of Ligne
     *
     * @param  list<T>  $lignes
     * @param  list<int>  $magasins
     * @param  Closure(T): int  $montant
     */
    public static function depuis(array $lignes, array $magasins, Closure $montant): self
    {
        $permis = array_flip($magasins);
        $parJour = [];

        foreach ($lignes as $ligne) {
            if (isset($permis[$ligne['magasin']])) {
                $parJour[$ligne['jour']] = ($parJour[$ligne['jour']] ?? 0) + $montant($ligne);
            }
        }

        return new self($parJour);
    }

    /**
     * Ces montants, déjà rangés par jour.
     *
     * @param  array<string, int>  $parJour
     */
    public static function deJours(array $parJour): self
    {
        return new self($parJour);
    }

    /**
     * La somme des jours du $du au $au compris ; zéro pour un intervalle
     * sans montant.
     */
    public function entre(string $du, string $au): int
    {
        $debut = $this->premierRangDepuis($du, false);
        $fin = $this->premierRangDepuis($au, true);

        return $fin > $debut ? $this->cumuls[$fin] - $this->cumuls[$debut] : 0;
    }

    /**
     * Le rang du premier jour qui n'est pas avant ce jour (ou, avec
     * $strictement, qui le suit), entre 0 et le nombre de jours.
     */
    private function premierRangDepuis(string $jour, bool $strictement): int
    {
        $bas = 0;
        $haut = count($this->jours);

        while ($bas < $haut) {
            $milieu = intdiv($bas + $haut, 2);

            if ($this->jours[$milieu] < $jour || ($strictement && $this->jours[$milieu] === $jour)) {
                $bas = $milieu + 1;
            } else {
                $haut = $milieu;
            }
        }

        return $bas;
    }
}

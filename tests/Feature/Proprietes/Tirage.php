<?php

namespace Tests\Feature\Proprietes;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Le hasard d'un cas des tests au hasard (décision de l'éditeur du
 * 01/11/2026, point 5 bis) : tiré d'une graine écrite, jamais du hasard
 * réel, il se rejoue toujours à l'identique.
 */
final class Tirage
{
    private Randomizer $hasard;

    public function __construct(public readonly int $graine)
    {
        $this->hasard = new Randomizer(new Xoshiro256StarStar($graine));
    }

    public function entier(int $minimum, int $maximum): int
    {
        return $this->hasard->getInt($minimum, $maximum);
    }

    /**
     * Vrai une fois sur $sur en moyenne.
     */
    public function uneFoisSur(int $sur): bool
    {
        return $this->entier(1, $sur) === 1;
    }

    /**
     * @template T
     *
     * @param  non-empty-list<T>  $valeurs
     * @return T
     */
    public function parmi(array $valeurs): mixed
    {
        return $valeurs[$this->entier(0, count($valeurs) - 1)];
    }

    /**
     * Une partie de ces valeurs, dans leur ordre, au moins une.
     *
     * @template T
     *
     * @param  non-empty-list<T>  $valeurs
     * @return non-empty-list<T>
     */
    public function quelquesUns(array $valeurs): array
    {
        $choisis = array_values(array_filter($valeurs, fn (): bool => $this->uneFoisSur(2)));

        return $choisis === [] ? [$this->parmi($valeurs)] : $choisis;
    }

    /**
     * Un montant en centimes découpé en $parts parts positives, dont la
     * somme fait le montant.
     *
     * @return non-empty-list<int>
     */
    public function decouper(int $montant, int $parts): array
    {
        $parts = max(1, min($parts, $montant));
        $coupures = [];

        while (count($coupures) < $parts - 1) {
            $coupures[$this->entier(1, $montant - 1)] = true;
        }

        $bornes = [0, ...array_keys($coupures), $montant];
        sort($bornes);
        $morceaux = [];

        for ($i = 1; $i < count($bornes); $i++) {
            $morceaux[] = $bornes[$i] - $bornes[$i - 1];
        }

        return $morceaux;
    }
}

<?php

namespace App\Domain\Calendrier\Connexions;

use App\Domain\Calendrier\Casts\Instant;
use DateTimeInterface;

/**
 * Une date liée à une requête (constructeur de requêtes, DB::table(),
 * requête brute) s'écrit en UTC, comme les colonnes qu'elle compare
 * (voir Instant), quel que soit son fuseau : Laravel l'écrirait sinon
 * dans le fuseau de l'objet.
 */
trait LieLesDatesEnUtc
{
    /**
     * @param  array<array-key, mixed>  $bindings
     * @return array<array-key, mixed>
     */
    public function prepareBindings(array $bindings): array
    {
        foreach ($bindings as $cle => $valeur) {
            if ($valeur instanceof DateTimeInterface) {
                $bindings[$cle] = Instant::enBase($valeur);
            }
        }

        return parent::prepareBindings($bindings);
    }
}

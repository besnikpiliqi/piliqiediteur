<?php

namespace Tests\Fixtures;

use App\Domain\Tenancy\Contracts\SourceDeHasard;

/**
 * Une source de hasard qui rend, dans l'ordre, les valeurs qu'un test lui
 * impose (voir GenerateurDIdentifiants), puis la dernière à chaque tirage
 * suivant : un test de concurrence la lie dans son processus et dans
 * l'autre (voir TestCase::demarrerUnProcessusConcurrent()), pour que deux
 * créations simultanées tirent la même valeur. Une classe nommée, et non
 * anonyme, pour qu'une opération concurrente la reconstruise.
 */
final class SourceDeHasardImposee implements SourceDeHasard
{
    /**
     * @param  non-empty-list<int>  $valeurs
     */
    public function __construct(private array $valeurs) {}

    public function entier(int $min, int $max): int
    {
        return count($this->valeurs) > 1 ? array_shift($this->valeurs) : $this->valeurs[0];
    }
}

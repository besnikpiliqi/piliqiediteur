<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;

/**
 * À utiliser sur tout job de queue qui touche des données propres à une
 * société (BelongsToSociete), comme WithTenantContext pour un magasin : un
 * worker n'a pas de session HTTP, et sans ce trait toute requête sur un
 * modèle de la société échouerait (SocieteScope, fermé par défaut) plutôt
 * que d'hériter de la société du job précédent.
 *
 * La classe qui l'utilise déclare sa propriété publique `int $societeId`,
 * sérialisée avec le job et initialisée à sa mise en file depuis la
 * société concernée, jamais depuis auth()->user() à l'exécution.
 */
trait WithSocieteContext
{
    protected function demarrerContexteSociete(): Societe
    {
        $societe = Societe::query()->findOrFail($this->societeId);

        app(TenantContext::class)->poserSociete($societe);

        return $societe;
    }
}

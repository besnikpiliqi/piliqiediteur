<?php

namespace App\Policies;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Base commune à toutes les policies de modèles BelongsToMagasin.
 *
 * Deuxième ligne de défense après MagasinScope (voir
 * app/Domain/Tenancy/Scopes/MagasinScope.php) : le global scope filtre déjà
 * les requêtes, mais une policy doit re-vérifier explicitement
 * l'appartenance au magasin actif avant toute action view/update/delete,
 * au cas où l'enregistrement aurait été résolu autrement (route model
 * binding avec une clé différente, relation déjà chargée avant un switch
 * de contexte, etc.). On ne fait jamais confiance à une seule couche.
 */
abstract class MagasinPolicy
{
    protected function appartientAuMagasinActif(Model $model): bool
    {
        $contexte = app(TenantContext::class);

        return $contexte->has() && (int) $model->getAttribute('magasin_id') === $contexte->id();
    }
}

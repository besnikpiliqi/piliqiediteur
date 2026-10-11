<?php

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre toute requête Eloquent sur un modèle propre à une société (voir
 * BelongsToSociete) par la société active du TenantContext.
 *
 * Fermé par défaut, comme MagasinScope : sans société active, la requête
 * est refusée (TenantContextMissingException) plutôt que de renvoyer les
 * données de toutes les sociétés. Une lecture volontairement hors de la
 * société active passe explicitement par withoutGlobalScope(self::class),
 * filtrée et justifiée là où elle se fait.
 */
class SocieteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->getTable().'.societe_id', app(TenantContext::class)->societe()->getKey());
    }
}

<?php

namespace App\Domain\Tenancy\Scopes;

use App\Domain\Tenancy\Exceptions\TenantContextMissingException;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre automatiquement toute requête Eloquent sur un modèle tenant par le
 * magasin actif du TenantContext.
 *
 * Fail-closed : si aucun contexte n'est résolu, on lève plutôt que de
 * renvoyer les données de tous les magasins. Un accès volontairement
 * cross-tenant (Super Admin) doit passer explicitement par
 * withoutGlobalScope(self::class), jamais par un contexte "vide" qui
 * désactiverait silencieusement le filtre.
 */
class MagasinScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->has()) {
            throw TenantContextMissingException::forModel($model::class);
        }

        $builder->where($model->getTable().'.magasin_id', $context->id());
    }
}

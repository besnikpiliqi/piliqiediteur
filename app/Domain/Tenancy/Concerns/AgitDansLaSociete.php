<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Une action de la société s'exécute dans le contexte de cette société,
 * jamais dans celui d'une autre déjà active : depuis son espace
 * d'administration (la société posée seule), depuis le panneau d'un de
 * ses magasins (son magasin actif), ou appelée directement. Le contexte
 * d'avant est restauré ensuite.
 */
trait AgitDansLaSociete
{
    /**
     * @template TResultat
     *
     * @param  Closure(): TResultat  $operation
     * @return TResultat
     *
     * @throws AuthorizationException
     */
    private function dansLaSociete(Societe $societe, string $refus, Closure $operation): mixed
    {
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new AuthorizationException($refus);
        }

        return $contexte->runAsSociete($societe, $operation);
    }
}

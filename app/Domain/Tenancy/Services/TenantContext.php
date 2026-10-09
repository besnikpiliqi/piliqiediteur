<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Exceptions\TenantContextMissingException;
use App\Domain\Tenancy\Models\Magasin;

/**
 * Source unique de vérité pour "quel magasin est actif", quel que soit le
 * point d'entrée : panneau Filament (magasin de l'URL, voir
 * BindTenantContextFromFilamentTenant), job de queue (contexte restauré
 * depuis le payload sérialisé, voir WithTenantContext), commande
 * Artisan/seeder (positionné explicitement).
 *
 * Lié en scoped() et non en singleton (voir TenancyServiceProvider) : une
 * instance par requête ou par job, oubliée entre deux jobs d'un même
 * worker -- jamais le magasin d'une exécution précédente.
 */
class TenantContext
{
    private ?Magasin $magasin = null;

    private bool $resolved = false;

    public function set(Magasin $magasin): void
    {
        $this->magasin = $magasin;
        $this->resolved = true;

        $this->invaliderPermissionsUtilisateurCourant();
    }

    public function clear(): void
    {
        $this->magasin = null;
        $this->resolved = false;

        $this->invaliderPermissionsUtilisateurCourant();
    }

    /**
     * Spatie Permission (mode teams) détermine les rôles/permissions
     * applicables en filtrant par team_id au moment de la requête, mais
     * Eloquent met en cache la relation `roles`/`permissions` sur
     * l'instance User après le premier accès (propriété magique). Si le
     * magasin actif change en cours de requête (switch de magasin, ou
     * simplement dans un test qui enchaîne plusieurs contextes), cette
     * collection mise en cache resterait celle de l'ANCIEN magasin tant
     * qu'on ne la force pas à se recharger. On l'invalide donc à chaque
     * changement de contexte, sur l'utilisateur authentifié s'il existe.
     */
    private function invaliderPermissionsUtilisateurCourant(): void
    {
        if (! auth()->check()) {
            return;
        }

        $utilisateur = auth()->user();

        if (method_exists($utilisateur, 'unsetRelation')) {
            $utilisateur->unsetRelation('roles');
            $utilisateur->unsetRelation('permissions');
        }
    }

    public function has(): bool
    {
        return $this->resolved && $this->magasin !== null;
    }

    public function magasin(): Magasin
    {
        if (! $this->has()) {
            throw TenantContextMissingException::forModel(Magasin::class);
        }

        return $this->magasin;
    }

    public function id(): int
    {
        return $this->magasin()->getKey();
    }

    /**
     * Exécute un callback dans un contexte tenant donné, puis restaure le
     * contexte précédent. Utilisé par les jobs de queue et les commandes
     * Super Admin qui doivent agir ponctuellement "en tant que" un magasin.
     */
    public function runAs(Magasin $magasin, \Closure $callback): mixed
    {
        $previous = $this->resolved ? $this->magasin : null;
        $previousResolved = $this->resolved;

        $this->set($magasin);

        try {
            return $callback();
        } finally {
            if ($previousResolved && $previous !== null) {
                $this->set($previous);
            } else {
                $this->clear();
            }
        }
    }
}

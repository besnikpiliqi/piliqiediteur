<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Fait pointer le système de "teams" de Spatie Permission sur notre propre
 * TenantContext plutôt que sur son état interne par défaut (qui ne connaît
 * ni Filament, ni nos jobs de queue, ni notre notion de magasin actif).
 *
 * Résultat : `$user->hasPermissionTo('invoice.issue')` est automatiquement
 * évalué pour le bon magasin, quel que soit le point d'entrée (panneau
 * Filament, API, job), sans jamais avoir à passer le tenant manuellement
 * à Spatie.
 *
 * Contrairement à MagasinScope, on ne lève pas d'exception si le contexte
 * est vide : une vérification de permission peut légitimement survenir
 * avant qu'un magasin actif soit choisi (ex: écran de sélection du
 * magasin). Dans ce cas, aucune permission scopée ne peut matcher -- c'est
 * un refus implicite, pas une fuite.
 */
class MagasinTeamResolver implements PermissionsTeamResolver
{
    // Spatie\Permission\PermissionRegistrar instancie ce resolver avec
    // `new (...)`, sans passer par le container -- pas d'injection de
    // constructeur possible ici. On résout TenantContext paresseusement
    // à chaque appel via le container (binding "scoped", donc toujours la
    // bonne instance pour la requête/job en cours).
    private function tenantContext(): TenantContext
    {
        return app(TenantContext::class);
    }

    public function getPermissionsTeamId(): int|string|null
    {
        return $this->tenantContext()->has() ? $this->tenantContext()->id() : null;
    }

    public function setPermissionsTeamId($id): void
    {
        if ($id === null) {
            $this->tenantContext()->clear();

            return;
        }

        if ($id instanceof Model) {
            if (! $id instanceof Magasin) {
                throw new \InvalidArgumentException('MagasinTeamResolver attend une instance de Magasin.');
            }

            $this->tenantContext()->set($id);

            return;
        }

        $this->tenantContext()->set(Magasin::query()->findOrFail($id));
    }
}

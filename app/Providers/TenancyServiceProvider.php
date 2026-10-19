<?php

namespace App\Providers;

use App\Domain\Authorization\ControleDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Tenancy\Contracts\SourceDeHasard;
use App\Domain\Tenancy\Services\SourceDeHasardCryptographique;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped() et non singleton() : le framework réinitialise les
        // bindings "scoped" entre chaque requête HTTP ET entre chaque job
        // de queue sur un worker longue durée (queue:work). Un singleton()
        // classique resterait positionné sur le magasin du job précédent
        // si un job suivant oubliait de repositionner son propre contexte
        // -- fuite de tenant silencieuse entre deux jobs sur le même
        // worker. scoped() rend cette classe d'erreur impossible.
        $this->app->scoped(TenantContext::class);
        // Même raison : l'opérateur d'une requête ou d'un job ne survit
        // jamais au suivant (voir OperateurCourant).
        $this->app->scoped(OperateurCourant::class);
        // Le hasard des identifiants de connexion : celui, cryptographique,
        // de PHP (voir GenerateurDIdentifiants).
        $this->app->bind(SourceDeHasard::class, SourceDeHasardCryptographique::class);
    }

    /**
     * Le seul rappel exécuté avant les policies : les droits du compte dans
     * le magasin actif selon son rôle, la lecture seule d'un magasin, la
     * porte de validation et la lecture seule de l'expert-comptable (voir
     * ControleDesDroits). Il passe en closure pour que la
     * Gate lise ses paramètres : sans utilisateur connecté, elle ne
     * l'appelle pas.
     */
    public function boot(): void
    {
        Gate::before((new ControleDesDroits)(...));
    }
}

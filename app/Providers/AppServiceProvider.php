<?php

namespace App\Providers;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Policies\ClientPolicy;
use App\Policies\FournisseurPolicy;
use App\Policies\ProduitPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Les modèles vivent sous App\Domain\<Domaine>\Models\... plutôt
        // qu'à plat sous App\Models. La convention par défaut de Laravel
        // chercherait la factory au même chemin sous Database\Factories
        // (ex: Database\Factories\Domain\Tenancy\Models\MagasinFactory).
        // On la fait pointer sur un répertoire plat Database\Factories\
        // {NomDuModele}Factory, plus simple à maintenir.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // Même raisonnement pour les Policies : l'auto-discovery de
        // Laravel ne devine pas App\Policies\ClientPolicy depuis
        // App\Domain\Contacts\Models\Client. On enregistre explicitement
        // plutôt que de compter sur une convention fragile.
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Fournisseur::class, FournisseurPolicy::class);
        Gate::policy(Produit::class, ProduitPolicy::class);
    }
}

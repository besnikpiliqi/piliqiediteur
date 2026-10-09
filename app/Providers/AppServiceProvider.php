<?php

namespace App\Providers;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Policies\AvoirFournisseurPolicy;
use App\Policies\AvoirPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ClotureCaissePersonnellePolicy;
use App\Policies\ClotureCaissePolicy;
use App\Policies\DevisPolicy;
use App\Policies\FactureAchatPolicy;
use App\Policies\FacturePolicy;
use App\Policies\FournisseurPolicy;
use App\Policies\MembreEquipePolicy;
use App\Policies\PaiementPolicy;
use App\Policies\ProduitPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SoumissionEReportingPolicy;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;

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
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(Devis::class, DevisPolicy::class);
        Gate::policy(Facture::class, FacturePolicy::class);
        Gate::policy(MagasinUser::class, MembreEquipePolicy::class);
        Gate::policy(Paiement::class, PaiementPolicy::class);
        Gate::policy(Avoir::class, AvoirPolicy::class);
        Gate::policy(ClotureCaisse::class, ClotureCaissePolicy::class);
        Gate::policy(ClotureCaissePersonnelle::class, ClotureCaissePersonnellePolicy::class);
        Gate::policy(FactureAchat::class, FactureAchatPolicy::class);
        Gate::policy(AvoirFournisseur::class, AvoirFournisseurPolicy::class);
        Gate::policy(SoumissionEReporting::class, SoumissionEReportingPolicy::class);

        $this->declarerLaSaisieEnDirect();
        $this->limiterLesChoixAuMagasin();
        $this->nommerLesChampsDansLesMessages();
    }

    /**
     * Un message de validation (voir lang/fr/validation.php) nomme le champ
     * par son libellé, en minuscule au milieu de la phrase (« Le champ nom
     * est obligatoire. »), sauf un sigle, qui garde ses majuscules (« Le
     * champ PU TTC... », jamais « pU TTC »).
     */
    private function nommerLesChampsDansLesMessages(): void
    {
        Field::configureUsing(function (Field $champ): void {
            $champ->validationAttribute(static function (Field $component): string {
                $libelle = strip_tags((string) $component->getLabel());

                return preg_match('/^\p{Lu}\p{Ll}/u', $libelle) === 1 ? Str::lcfirst($libelle) : $libelle;
            });
        });
    }

    /**
     * Un Select relationship() ne valide son choix qu'avec une règle
     * « exists » sur toute la table (voir Select::relationship()) : une
     * requête Livewire forgée y ferait passer le client ou le fournisseur
     * d'un autre magasin. Chaque Select qui pointe vers un modèle de
     * magasin exige donc en plus BelongsToMagasin::regleExisteDansLeMagasin(),
     * sans qu'aucun formulaire puisse l'oublier.
     *
     * Le client ou le fournisseur d'un nouveau document se choisit avec
     * relationProposable() plutôt que relationship() : seules les fiches
     * actives et non archivées sont proposées, et acceptées côté serveur
     * (voir ProposableSiActif).
     *
     * La valeur déjà enregistrée sur la fiche modifiée n'est jamais
     * revérifiée : elle n'a pas été choisie dans ce formulaire, et une
     * fiche désactivée ou archivée depuis reste rattachée telle quelle.
     */
    private function limiterLesChoixAuMagasin(): void
    {
        $choisiDansCeFormulaire = static function (Select $component): bool {
            $relation = $component->getRelationship();
            $fiche = $relation->getParent();

            return ! $fiche->exists
                || (string) $component->getState() !== (string) $fiche->getOriginal($relation->getForeignKeyName());
        };

        Select::configureUsing(function (Select $select) use ($choisiDansCeFormulaire): void {
            $select->rule(
                static fn (Select $component): Exists => $component->getRelationship()->getRelated()::regleExisteDansLeMagasin(),
                static function (Select $component) use ($choisiDansCeFormulaire): bool {
                    $relation = $component->getRelationship();

                    return $relation instanceof BelongsTo
                        && in_array(BelongsToMagasin::class, class_uses_recursive($relation->getRelated()), true)
                        && $choisiDansCeFormulaire($component);
                },
            );
        });

        Select::macro('relationProposable', function (string $relation, string $attributAffiche) use ($choisiDansCeFormulaire): Select {
            /** @var Select $this */
            return $this
                ->relationship(
                    $relation,
                    $attributAffiche,
                    static fn (Builder $query, Select $component, ?Model $record): Builder => $query->proposables(
                        $record?->getOriginal($component->getRelationship()->getForeignKeyName()),
                    ),
                )
                ->rule(
                    static fn (Select $component): Exists => $component->getRelationship()->getRelated()::regleProposableDansLeMagasin(),
                    $choisiDansCeFormulaire,
                );
        });
    }

    /**
     * Champ calculé en direct pendant la frappe (montant, quantité, taux) :
     * chaque frappe recalcule le reste du formulaire, mais le champ lui-même
     * n'est jamais réécrit par le serveur tant qu'il a le focus
     * (modificateur Livewire/Alpine "unintrusive") -- sinon une réponse
     * arrivée en pleine frappe effaçait la virgule de "12,5" et laissait
     * "125". Voir aussi resources/views/filament/app/saisie-protegee.blade.php,
     * qui renvoie la valeur affichée si une réponse l'a fait diverger.
     */
    private function declarerLaSaisieEnDirect(): void
    {
        TextInput::macro('saisieEnDirect', function (): TextInput {
            /** @var TextInput $this */
            return $this->live()->stateBindingModifiers(['live', 'unintrusive']);
        });
    }
}

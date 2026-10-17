<?php

namespace App\Providers;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Recherche\ConnecteurSqlite;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Http\Responses\ReponseDeConnexion;
use App\Models\User;
use App\Policies\AffectationPolicy;
use App\Policies\AvoirFournisseurPolicy;
use App\Policies\AvoirPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ClotureCaissePersonnellePolicy;
use App\Policies\ClotureCaissePolicy;
use App\Policies\DevisPolicy;
use App\Policies\EnregistrementFiscalPolicy;
use App\Policies\FactureAchatPolicy;
use App\Policies\FacturePolicy;
use App\Policies\FournisseurPolicy;
use App\Policies\MagasinDeLaSocietePolicy;
use App\Policies\MembrePolicy;
use App\Policies\PaiementPolicy;
use App\Policies\ProduitPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SocietePolicy;
use App\Policies\SoumissionEReportingPolicy;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator as ValidatorDeLaravel;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * scoped() : l'instant que donne le verrou du registre fiscal vaut pour
     * toutes les écritures fiscales de la transaction, transactions
     * imbriquées comprises (voir RegistreFiscal), et s'oublie entre deux
     * requêtes ou deux jobs.
     *
     * Chaque connexion SQLite reçoit la fonction des recherches sans casse
     * ni accents (voir ConnecteurSqlite).
     *
     * Une connexion réussie mène à l'accueil du panneau, même pour un
     * compte qui n'a encore aucun magasin (voir ReponseDeConnexion), à la
     * place de la réponse de Filament.
     */
    public function register(): void
    {
        $this->app->scoped(RegistreFiscal::class);
        $this->app->bind('db.connector.sqlite', ConnecteurSqlite::class);
        $this->app->bind(LoginResponse::class, ReponseDeConnexion::class);
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
        Gate::policy(Paiement::class, PaiementPolicy::class);
        Gate::policy(Avoir::class, AvoirPolicy::class);
        Gate::policy(ClotureCaisse::class, ClotureCaissePolicy::class);
        Gate::policy(ClotureCaissePersonnelle::class, ClotureCaissePersonnellePolicy::class);
        Gate::policy(FactureAchat::class, FactureAchatPolicy::class);
        Gate::policy(AvoirFournisseur::class, AvoirFournisseurPolicy::class);
        Gate::policy(SoumissionEReporting::class, SoumissionEReportingPolicy::class);
        Gate::policy(EnregistrementFiscal::class, EnregistrementFiscalPolicy::class);
        Gate::policy(Societe::class, SocietePolicy::class);
        Gate::policy(Magasin::class, MagasinDeLaSocietePolicy::class);
        Gate::policy(User::class, MembrePolicy::class);
        Gate::policy(Affectation::class, AffectationPolicy::class);

        Auth::provider('comptes', static fn (Application $app, array $config): FournisseurDesComptes => new FournisseurDesComptes($app['hash'], $config['model']));

        $this->nommerLesModelesPolymorphes();
        $this->declarerLaSaisieEnDirect();
        $this->declarerLaRechercheSansAccents();
        $this->declarerLAdresseDeConnexion();
        $this->limiterLesChoixAuMagasin();
        $this->garderLesChoixDuPanneauSociete();
        $this->nommerLesChampsDansLesMessages();
        $this->ecrireLesDatesEnFrancaisDansLesMessages();
        $this->neJamaisMettreEnFormeLesTextesDesEmails();
    }

    /**
     * Un texte qu'une personne a saisi (une raison sociale, un nom, un nom
     * de magasin) et qu'un gabarit Markdown d'e-mail affiche par {{ }}
     * arrive tel qu'il a été écrit : jamais un lien cliquable, une image ou
     * une balise, envoyés depuis le domaine de l'éditeur, qui mèneraient
     * ailleurs que ce qu'ils annoncent (« [Votre compte est bloqué](...) »
     * dans une raison sociale). {{ }} n'échappe que le HTML ; Laravel
     * échappe alors aussi les crochets du Markdown.
     */
    private function neJamaisMettreEnFormeLesTextesDesEmails(): void
    {
        Markdown::withSecuredEncoding();
    }

    /**
     * Le nom sous lequel un modèle s'écrit dans une colonne polymorphique
     * (document_type du registre fiscal et des envois, source_type du
     * grand livre du stock, transmissible_type, notifiable_type,
     * cible_type du journal d'audit, resultat_type des demandes déjà
     * traitées) : un alias stable plutôt que le nom de sa
     * classe, qu'un déplacement de fichier changerait, alors que le
     * registre fiscal le signe (voir RegistreFiscal). Sans
     * enforceMorphMap() : un modèle absent de la liste garde le nom de sa
     * classe.
     */
    private function nommerLesModelesPolymorphes(): void
    {
        Relation::morphMap([
            'avoir' => Avoir::class,
            'avoir_fournisseur' => AvoirFournisseur::class,
            'cloture_caisse' => ClotureCaisse::class,
            'cloture_caisse_personnelle' => ClotureCaissePersonnelle::class,
            'devis' => Devis::class,
            'envoi_document' => EnvoiDocument::class,
            'facture' => Facture::class,
            'facture_achat' => FactureAchat::class,
            'ligne_avoir' => LigneAvoir::class,
            'magasin' => Magasin::class,
            'paiement' => Paiement::class,
            'periode_activite' => PeriodeActivite::class,
            'produit' => Produit::class,
            'societe' => Societe::class,
            'soumission_ereporting' => SoumissionEReporting::class,
            'utilisateur' => User::class,
        ]);
    }

    /**
     * Un message de validation (voir lang/fr/validation.php) nomme le champ
     * par son libellé, en minuscule au milieu de la phrase (« Le champ nom
     * est obligatoire. », « Le champ e-mail... »), sauf quand il commence
     * par un sigle, qui garde ses majuscules (« Le champ PU TTC... », jamais
     * « pU TTC »).
     */
    private function nommerLesChampsDansLesMessages(): void
    {
        Field::configureUsing(function (Field $champ): void {
            $champ->validationAttribute(static function (Field $component): string {
                $libelle = strip_tags((string) $component->getLabel());

                return preg_match('/^\p{Lu}{2,}(?!\p{Ll})/u', $libelle) === 1 ? $libelle : Str::lcfirst($libelle);
            });
        });
    }

    /**
     * Le message d'une règle de date (after, before...) écrit la date
     * comparée comme on l'écrit en français (« postérieure ou égale au
     * 09/10/2026 », suivie de l'heure si elle en a une), jamais sous sa
     * forme technique « 2026-10-09 00:00:00 ». Une règle qui compare à un
     * autre champ nomme ce champ par son libellé, comme le fait Laravel.
     */
    private function ecrireLesDatesEnFrancaisDansLesMessages(): void
    {
        foreach (['after', 'after_or_equal', 'before', 'before_or_equal', 'date_equals'] as $regle) {
            Validator::replacer($regle, static function (string $message, string $attribute, string $rule, array $parameters, ValidatorDeLaravel $validator): string {
                if (strtotime($parameters[0]) === false) {
                    return str_replace(':date', $validator->getDisplayableAttribute($parameters[0]), $message);
                }

                $date = Carbon::parse($parameters[0]);

                return str_replace(':date', $date->format($date->isStartOfDay() ? 'd/m/Y' : 'd/m/Y à H:i'), $message);
            });
        }
    }

    /**
     * Un Select relationship() ne valide son choix qu'avec une règle
     * « exists » sur toute la table (voir Select::relationship()) : une
     * requête Livewire forgée y ferait passer le client ou le fournisseur
     * d'un autre magasin. Chaque Select qui pointe vers un modèle de
     * magasin exige donc en plus BelongsToMagasin::regleExisteDansLeMagasin(),
     * sans qu'aucun formulaire puisse l'oublier. Cette règle refuse aussi
     * une fiche archivée.
     *
     * Le client ou le fournisseur d'un document se choisit avec
     * relationProposable() plutôt que relationship() : seules les fiches
     * non archivées sont proposées, plus celle déjà rattachée (voir
     * Proposable), et la recherche ignore la casse et les accents (voir
     * RechercheSansAccents).
     *
     * La valeur déjà enregistrée sur la fiche modifiée n'est jamais
     * revérifiée : elle n'a pas été choisie dans ce formulaire, et une
     * fiche archivée depuis reste rattachée telle quelle.
     */
    private function limiterLesChoixAuMagasin(): void
    {
        Select::configureUsing(function (Select $select): void {
            $select->rule(
                static fn (Select $component): Exists => $component->getRelationship()->getRelated()::regleExisteDansLeMagasin(),
                static function (Select $component): bool {
                    $relation = $component->getRelationship();

                    if (! $relation instanceof BelongsTo || ! in_array(BelongsToMagasin::class, class_uses_recursive($relation->getRelated()), true)) {
                        return false;
                    }

                    $fiche = $relation->getParent();

                    return ! $fiche->exists
                        || (string) $component->getState() !== (string) $fiche->getOriginal($relation->getForeignKeyName());
                },
            );
        });

        Select::macro('relationProposable', function (string $relation, string $attributAffiche): Select {
            /** @var Select $this */
            return $this->relationship(
                $relation,
                $attributAffiche,
                static fn (Builder $query, Select $component, ?Model $record): Builder => $query->proposables(
                    $record?->getOriginal($component->getRelationship()->getForeignKeyName()),
                ),
            )->getSearchResultsUsing(static function (Select $component, string $search) use ($attributAffiche): array {
                /** @var BelongsTo<Model, Model> $relation */
                $relation = Relation::noConstraints(static fn (): Relation => $component->getRelationship());
                $cle = $relation->getRelated()->getKeyName();
                $proposables = $relation->getQuery()->proposables($component->getRecord()?->getOriginal($relation->getForeignKeyName()));

                return RechercheSansAccents::appliquer($proposables, $search, [$attributAffiche])
                    ->orderBy($attributAffiche)
                    ->orderBy($cle)
                    ->limit($component->getOptionsLimit())
                    ->pluck($attributAffiche, $cle)
                    ->all();
            });
        });
    }

    /**
     * Filament ne cloisonne pas les options d'un Select (filtres de table
     * compris) ni d'une CheckboxList : dans l'espace d'administration d'une
     * société, où Magasin et User n'ont pas de scope global, des options
     * tirées de ces modèles proposeraient les magasins ou les comptes de
     * toutes les sociétés. Chaque champ de ce panneau se contrôle donc à son
     * rendu (voir verifierLesChoixDuPanneauSociete()). Les rappels
     * configureUsing sont statiques et globaux : ils ne touchent à rien
     * hors de ce panneau. Un champ de ce panneau qui redéfinit ses
     * attributs HTML le fait avec extraAttributes(..., merge: true), pour
     * garder ce contrôle.
     */
    private function garderLesChoixDuPanneauSociete(): void
    {
        foreach ([Select::class, CheckboxList::class] as $champ) {
            $champ::configureUsing(static function (Select|CheckboxList $champ): void {
                if (Filament::getCurrentPanel()?->getId() !== 'societe') {
                    return;
                }

                $champ->extraAttributes(static function (Select|CheckboxList $component): array {
                    self::verifierLesChoixDuPanneauSociete($component);

                    return [];
                }, merge: true);
            });
        }
    }

    /**
     * Les options d'un champ du panneau société ne portent sur des magasins
     * ou des comptes que par une relation de la société affichée (ses
     * magasins, ses membres) ; un modèle propre à une société l'est déjà
     * par SocieteScope.
     *
     * @throws LogicException
     */
    public static function verifierLesChoixDuPanneauSociete(Select|CheckboxList $component): void
    {
        $relation = $component->getRelationship();

        if ($relation === null || ! in_array($relation->getRelated()::class, [Magasin::class, User::class], true)) {
            return;
        }

        $tenant = Filament::getTenant();

        if (! $relation->getParent() instanceof Societe || ! $tenant instanceof Societe || ! $relation->getParent()->is($tenant)) {
            throw new LogicException("Le champ « {$component->getName()} » de l'espace société propose des magasins ou des comptes sans passer par une relation de la société.");
        }
    }

    /**
     * Une colonne de tableau recherchée sans tenir compte de la casse ni
     * des accents (voir RechercheSansAccents), à la place de searchable() :
     * « creme » trouve « Crème ». Une colonne « relation.colonne » cherche
     * dans la fiche liée.
     */
    private function declarerLaRechercheSansAccents(): void
    {
        TextColumn::macro('rechercheSansAccents', function (): TextColumn {
            /** @var TextColumn $this */
            return $this->searchable(query: RechercheSansAccents::dans($this->getName()));
        });
    }

    /**
     * L'adresse e-mail d'un compte (inscription, profil, nouveau membre)
     * se valide déjà en minuscules et sans espaces autour, comme elle sera
     * enregistrée (voir User::normaliserEmail()) : « A@x.fr » est refusée
     * comme doublon de « a@x.fr ».
     */
    private function declarerLAdresseDeConnexion(): void
    {
        TextInput::macro('adresseDeConnexion', function (): TextInput {
            /** @var TextInput $this */
            return $this
                ->mutateStateForValidationUsing(static fn (?string $state): ?string => $state === null ? null : User::normaliserEmail($state))
                ->dehydrateStateUsing(static fn (?string $state): ?string => $state === null ? null : User::normaliserEmail($state));
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

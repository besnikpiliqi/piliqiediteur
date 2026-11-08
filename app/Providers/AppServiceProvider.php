<?php

namespace App\Providers;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Connexions\ConnexionPostgres;
use App\Domain\Calendrier\Connexions\ConnexionSqlite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Services\OrigineDesProduits;
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
use App\Domain\Facturation\Models\RecuPaiement;
use App\Domain\Recherche\ConnecteurSqlite;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Responses\ReponseDeConnexion;
use App\Models\User;
use App\Policies\AffectationPolicy;
use App\Policies\AvoirFournisseurPolicy;
use App\Policies\AvoirPolicy;
use App\Policies\CabinetPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ClotureCaissePersonnellePolicy;
use App\Policies\ClotureCaissePolicy;
use App\Policies\DevisPolicy;
use App\Policies\EnregistrementFiscalPolicy;
use App\Policies\EntreeAuditPolicy;
use App\Policies\FactureAchatPolicy;
use App\Policies\FacturePolicy;
use App\Policies\FournisseurPolicy;
use App\Policies\InventaireOuverturePolicy;
use App\Policies\InvitationCabinetPolicy;
use App\Policies\LienCabinetPolicy;
use App\Policies\MagasinDeLaSocietePolicy;
use App\Policies\MembrePolicy;
use App\Policies\PaiementPolicy;
use App\Policies\PosteReconnuPolicy;
use App\Policies\ProduitPolicy;
use App\Policies\RecuPaiementPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SocietePolicy;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Markdown;
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
     * Le contexte d'origine des produits (voir OrigineDesProduits) vit de
     * même le temps d'une requête ou d'un job, jamais au-delà.
     *
     * Chaque connexion SQLite reçoit la fonction des recherches sans casse
     * ni accents (voir ConnecteurSqlite).
     *
     * Une connexion réussie mène à l'accueil du panneau, même pour un
     * compte qui n'a encore aucun magasin (voir ReponseDeConnexion), à la
     * place de la réponse de Filament.
     *
     * Les connexions SQLite et PostgreSQL lient leurs dates en UTC (voir
     * LieLesDatesEnUtc).
     */
    public function register(): void
    {
        $this->app->scoped(RegistreFiscal::class);
        $this->app->scoped(OrigineDesProduits::class);
        $this->app->bind('db.connector.sqlite', ConnecteurSqlite::class);
        $this->app->bind(LoginResponse::class, ReponseDeConnexion::class);
        Connection::resolverFor('sqlite', static fn (mixed $pdo, string $base, string $prefixe, array $configuration): ConnexionSqlite => new ConnexionSqlite($pdo, $base, $prefixe, $configuration));
        Connection::resolverFor('pgsql', static fn (mixed $pdo, string $base, string $prefixe, array $configuration): ConnexionPostgres => new ConnexionPostgres($pdo, $base, $prefixe, $configuration));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Calendrier::exigerUnFuseauUtc();
        $this->interdireLesRequetesCacheesHorsProduction();

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
        Gate::policy(RecuPaiement::class, RecuPaiementPolicy::class);
        Gate::policy(Avoir::class, AvoirPolicy::class);
        Gate::policy(ClotureCaisse::class, ClotureCaissePolicy::class);
        Gate::policy(ClotureCaissePersonnelle::class, ClotureCaissePersonnellePolicy::class);
        Gate::policy(FactureAchat::class, FactureAchatPolicy::class);
        Gate::policy(AvoirFournisseur::class, AvoirFournisseurPolicy::class);
        Gate::policy(InventaireOuverture::class, InventaireOuverturePolicy::class);
        Gate::policy(EnregistrementFiscal::class, EnregistrementFiscalPolicy::class);
        Gate::policy(Societe::class, SocietePolicy::class);
        Gate::policy(Magasin::class, MagasinDeLaSocietePolicy::class);
        Gate::policy(User::class, MembrePolicy::class);
        Gate::policy(Affectation::class, AffectationPolicy::class);
        Gate::policy(PosteReconnu::class, PosteReconnuPolicy::class);
        Gate::policy(Cabinet::class, CabinetPolicy::class);
        Gate::policy(LienCabinet::class, LienCabinetPolicy::class);
        Gate::policy(InvitationCabinet::class, InvitationCabinetPolicy::class);
        Gate::policy(EntreeAudit::class, EntreeAuditPolicy::class);

        Auth::provider('comptes', static fn (Application $app, array $config): FournisseurDesComptes => new FournisseurDesComptes($app['hash'], $config['model']));

        $this->nommerLesModelesPolymorphes();
        $this->declarerLaSaisieEnDirect();
        $this->declarerLaRechercheSansAccents();
        $this->afficherLesDatesAuCalendrier();
        $this->declarerLAdresseDeConnexion();
        $this->limiterLesChoixAuMagasin();
        $this->garderLesChoixDuPanneauSociete();
        $this->nommerLesChampsDansLesMessages();
        $this->ecrireLesDatesEnFrancaisDansLesMessages();
        $this->neJamaisMettreEnFormeLesTextesDesEmails();
    }

    /**
     * Hors production (développement, tests, CI), une relation lue fiche
     * par fiche dans une boucle (une requête par fiche, « N+1 ») et un
     * attribut lu sans avoir été chargé (une colonne absente du select)
     * lèvent une exception au lieu de passer inaperçus : chaque liste,
     * tableau de bord ou PDF charge d'avance ce qu'il lit. En production,
     * rien ne change : la page s'affiche, plus lentement.
     */
    private function interdireLesRequetesCacheesHorsProduction(): void
    {
        $horsProduction = ! $this->app->isProduction();

        Model::preventLazyLoading($horsProduction);
        Model::preventAccessingMissingAttributes($horsProduction);
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
            'cabinet' => Cabinet::class,
            'cloture_caisse' => ClotureCaisse::class,
            'cloture_caisse_personnelle' => ClotureCaissePersonnelle::class,
            'devis' => Devis::class,
            'envoi_document' => EnvoiDocument::class,
            'facture' => Facture::class,
            'facture_achat' => FactureAchat::class,
            'inventaire_ouverture' => InventaireOuverture::class,
            'invitation_cabinet' => InvitationCabinet::class,
            'ligne_avoir' => LigneAvoir::class,
            'lien_cabinet' => LienCabinet::class,
            'magasin' => Magasin::class,
            'paiement' => Paiement::class,
            'produit' => Produit::class,
            'recu_paiement' => RecuPaiement::class,
            'societe' => Societe::class,
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
     * forme technique « 2026-10-09 00:00:00 ». La règle compare le texte
     * saisi au texte de la règle, sans fuseau : le message reprend ce
     * texte tel qu'il est écrit. Une règle qui compare à un autre champ
     * nomme ce champ par son libellé, comme le fait Laravel.
     */
    private function ecrireLesDatesEnFrancaisDansLesMessages(): void
    {
        foreach (['after', 'after_or_equal', 'before', 'before_or_equal', 'date_equals'] as $regle) {
            Validator::replacer($regle, static function (string $message, string $attribute, string $rule, array $parameters, ValidatorDeLaravel $validator): string {
                $jour = Calendrier::jourValide(substr($parameters[0], 0, 10));

                if ($jour === null) {
                    return str_replace(':date', $validator->getDisplayableAttribute($parameters[0]), $message);
                }

                $heure = substr($parameters[0], 11, 5);

                return str_replace(':date', Calendrier::afficherJour($jour, 'd/m/Y').(in_array($heure, ['', '00:00'], true) ? '' : ' à '.$heure), $message);
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
     * RechercheSansAccents) ; elle cherche dans l'attribut affiché, ou dans
     * les colonnes données (celles du client d'une vente, voir
     * ClientResource::colonnesDeRecherche()).
     *
     * La valeur déjà enregistrée sur la fiche modifiée n'est jamais
     * revérifiée : elle n'a pas été choisie dans ce formulaire, et une
     * fiche archivée depuis reste rattachée telle quelle.
     */
    private function limiterLesChoixAuMagasin(): void
    {
        Select::configureUsing(function (Select $select): void {
            $select->rule(
                static fn (Select $component): ?Exists => self::regleDuChoixDansLeMagasin($component),
                static fn (Select $component): bool => self::regleDuChoixDansLeMagasin($component) !== null,
            );
        });

        Select::macro('relationProposable', function (string $relation, string $attributAffiche, ?array $colonnesDeRecherche = null): Select {
            return $this->relationship(
                $relation,
                $attributAffiche,
                static fn (Builder $query, Select $component, ?Model $record): Builder => $query->scopes(['proposables' => [
                    $record?->getOriginal(AppServiceProvider::relationDuChoix($component)->getForeignKeyName()),
                ]]),
            )->getSearchResultsUsing(static function (Select $component, string $search) use ($attributAffiche, $colonnesDeRecherche): array {
                $relation = Relation::noConstraints(static fn (): BelongsTo => AppServiceProvider::relationDuChoix($component));
                $cle = $relation->getRelated()->getKeyName();
                $proposables = $relation->getQuery();
                $proposables->scopes(['proposables' => [$component->getRecord()?->getOriginal($relation->getForeignKeyName())]]);

                return RechercheSansAccents::appliquer($proposables, $search, $colonnesDeRecherche ?? [$attributAffiche])
                    ->orderBy($attributAffiche)
                    ->orderBy($cle)
                    ->limit($component->getOptionsLimit())
                    ->pluck($attributAffiche, $cle)
                    ->all();
            });
        });
    }

    /**
     * La règle « exists » d'un choix d'une fiche d'un magasin (un modèle
     * BelongsToMagasin, le seul qui sait regleExisteDansLeMagasin()), à
     * vérifier quand la fiche est nouvelle ou que le choix a changé ; null
     * pour tout autre champ.
     */
    private static function regleDuChoixDansLeMagasin(Select $component): ?Exists
    {
        $relation = $component->getRelationship();

        if (! $relation instanceof BelongsTo) {
            return null;
        }

        $modele = $relation->getRelated()::class;
        $fiche = $relation->getParent();

        if (! method_exists($modele, 'regleExisteDansLeMagasin')
            || ($fiche->exists && (string) $component->getState() === (string) $fiche->getOriginal($relation->getForeignKeyName()))) {
            return null;
        }

        return $modele::regleExisteDansLeMagasin();
    }

    /**
     * La relation d'un choix proposable (relationProposable()) : toujours
     * celle d'une fiche vers le client ou le fournisseur qu'elle désigne.
     * Publique : la macro s'exécute dans la portée du Select qui l'appelle.
     *
     * @return BelongsTo<Model, Model>
     *
     * @throws LogicException
     */
    public static function relationDuChoix(Select $component): BelongsTo
    {
        $relation = $component->getRelationship();

        if (! $relation instanceof BelongsTo) {
            throw new LogicException("Le choix « {$component->getName()} » n'est pas celui d'une fiche liée (BelongsTo).");
        }

        return $relation;
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
     * par SocieteScope. Une relation BelongsToThrough, que Filament sait
     * lire quand son paquet est installé, n'existe pas ici.
     *
     * @throws LogicException
     */
    public static function verifierLesChoixDuPanneauSociete(Select|CheckboxList $component): void
    {
        $relation = $component->getRelationship();
        $connue = $relation instanceof BelongsTo || $relation instanceof BelongsToMany || $relation instanceof HasOneOrMany || $relation instanceof HasOneOrManyThrough;

        if (! $connue || ! in_array($relation->getRelated()::class, [Magasin::class, User::class], true)) {
            return;
        }

        $tenant = Filament::getTenant();

        if (! $relation->getParent() instanceof Societe || ! $tenant instanceof Societe || ! $relation->getParent()->is($tenant)) {
            throw new LogicException("Le champ « {$component->getName()} » de l'espace société propose des magasins ou des comptes sans passer par une relation de la société.");
        }
    }

    /**
     * Les colonnes et les entrées de Filament affichent un instant à
     * l'heure du calendrier d'affichage (->dateTime(), y compris réduit à
     * son jour par ->dateTime('d/m/Y')) : celui du magasin actif dans son
     * panneau, celui de Paris dans l'espace de la société (voir
     * TenantContext::calendrierDAffichage()), lu au rendu ; un jour (cast
     * JourCivil) s'affiche tel quel par ->jour(), sans aucun fuseau :
     * ->date() relirait le jour comme un minuit du fuseau de
     * l'application, puis le convertirait à celui de la colonne, qui peut
     * donner la veille (aux Antilles).
     */
    private function afficherLesDatesAuCalendrier(): void
    {
        foreach ([TextColumn::class, TextEntry::class] as $composant) {
            $composant::configureUsing(static function (TextColumn|TextEntry $composant): void {
                $composant->timezone(static fn (): string => app(TenantContext::class)->calendrierDAffichage()->fuseau());
            });
            $composant::macro('jour', function (string $format = 'd/m/Y'): TextColumn|TextEntry {
                return $this->formatStateUsing(static fn (?string $state): ?string => blank($state) ? null : Calendrier::afficherJour($state, $format));
            });
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
            return $this->live()->stateBindingModifiers(['live', 'unintrusive']);
        });
    }
}

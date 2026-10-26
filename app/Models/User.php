<?php

namespace App\Models;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Un compte n'appartient qu'à une seule société (societe_id), avec son
 * rôle dans la société (voir RoleSociete) :
 * - son propriétaire, qui supervise et contrôle : tous les droits dans la
 *   société et dans chacun de ses magasins, sans case ni affectation, sauf
 *   celui de vendre, qu'il n'a jamais (voir
 *   PermissionRegistry::duProprietaire()) ;
 * - un administrateur, avec ses autorisations de société et sa portée
 *   (tous les magasins ou une sélection : ses affectations sans rôle, qui
 *   portent ses cases) ;
 * - un expert-comptable, sans affectation ni case, qui lit chaque magasin
 *   réel de la société (voir PermissionRegistry::consultations()) ;
 * - un membre, affecté à un seul magasin, celui de son identifiant (voir
 *   Identifiant), avec un rôle de magasin : responsable (toutes les
 *   autorisations de ce magasin, sans case), vendeur ou observateur (ses
 *   cases dans ce magasin) ; une personne qui travaille dans plusieurs
 *   magasins y a un compte dans chacun.
 *
 * Ses droits se lisent selon son rôle, en échouant fermé : une affectation
 * ou une autorisation qui contredit son rôle (restée en base après la
 * suppression d'un déclencheur, par exemple) est lue comme inexistante
 * (voir autorisationsDans() et GardesDesDroits). Son rôle de société, son
 * statut, sa société et ses droits ne se remplissent jamais par masse
 * (hors de $fillable) : ils ne s'écrivent que par les actions de la
 * société, qui les inscrivent au journal des événements de chaque magasin
 * touché (voir JournalDesDroits).
 *
 * Chaque compte, propriétaire compris, a un identifiant de connexion de 8
 * chiffres, tiré au hasard, qui ne change jamais, même quand le compte
 * change de rôle (voir Identifiant). Un compte créé par la société a pour
 * premier mot de passe son identifiant (mot_de_passe_est_l_identifiant),
 * que la personne change quand elle le veut ; un responsable, un
 * administrateur ou un expert-comptable le change à sa première connexion
 * (voir doitChoisirSonMotDePasse()). Seul un compte actif agit (voir
 * peutAgir()).
 *
 * Un compte ne s'efface pas : retiré, il garde son rôle, son identifiant
 * et son historique. Son adresse e-mail est l'adresse de connexion du
 * propriétaire, confirmée par un lien et unique parmi les propriétaires
 * (voir aUneAdresseDeConnexion()), ou un simple contact pour tout autre
 * compte.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'nouvelle_adresse_jeton_hache', 'jeton_acces_redonne'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Les valeurs par défaut de la migration, pour les colonnes que lisent
     * les droits : un compte tout juste instancié répond comme il le fera
     * relu en base.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role_societe' => 'membre',
        'statut' => 'actif',
        'mot_de_passe_est_l_identifiant' => false,
        'signaler_le_prochain_mot_de_passe' => false,
        'statut_decide_par_le_proprietaire' => false,
    ];

    /**
     * Les autorisations du compte dans chaque magasin, lues une fois par
     * instance (voir autorisationsDans()) pour les écrans, oubliées à
     * chaque écriture de droits et à chaque refresh(). Une action ne s'y
     * fie jamais : elle questionne un compte relu en base (voir
     * LecteurDesDroits et JournalDesDroits).
     *
     * @var array<int, list<string>>
     */
    private array $autorisationsParMagasin = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => Instant::class,
            'code_du_courrier_saisi_le' => Instant::class,
            'password' => 'hashed',
            'role_societe' => RoleSociete::class,
            'statut' => StatutCompte::class,
            'statut_decide_par_le_proprietaire' => 'boolean',
            'mot_de_passe_est_l_identifiant' => 'boolean',
            'signaler_le_prochain_mot_de_passe' => 'boolean',
            'premiere_connexion_le' => Instant::class,
            'derniere_connexion_le' => Instant::class,
            'acces_redonne_le' => Instant::class,
            'acces_redonne_en_presence' => 'boolean',
            'nouvelle_adresse_expire_le' => Instant::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Une adresse e-mail de compte s'enregistre et se compare en
     * minuscules, sans espaces autour : « A@x.fr » et « a@x.fr » sont la
     * même adresse. La connexion et « Mot de passe oublié » cherchent ainsi
     * l'adresse de connexion d'un propriétaire (voir FournisseurDesComptes),
     * et les formulaires la valident déjà sous cette forme (voir la macro
     * adresseDeConnexion()).
     */
    public static function normaliserEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: static fn (?string $email): ?string => $email === null ? null : self::normaliserEmail($email));
    }

    /**
     * Recharge le compte, droits compris : la mémoire des autorisations ne
     * survit pas.
     */
    public function refresh(): static
    {
        $this->oublierLesDroits();

        return parent::refresh();
    }

    /**
     * @return BelongsTo<Societe, $this>
     */
    public function societe(): BelongsTo
    {
        return $this->belongsTo(Societe::class);
    }

    /**
     * Ses affectations aux magasins de sa société (voir Affectation).
     *
     * @return HasMany<Affectation, $this>
     */
    public function affectations(): HasMany
    {
        return $this->hasMany(Affectation::class);
    }

    /**
     * Qui a créé ce compte pour la personne (voir CreationDesComptes, qui
     * compte ses créations du jour).
     *
     * @return BelongsTo<User, $this>
     */
    public function createur(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cree_par');
    }

    /**
     * Son identifiant de connexion, lu dans sa propre société, quelle que
     * soit la société active : il ne se montre qu'à la personne, au
     * propriétaire, au responsable de son magasin pour un vendeur ou un
     * observateur, et à un administrateur par un geste tracé (voir
     * PorteeDesDroits::voitLIdentifiant()). Null pour un compte qui n'en a
     * pas encore, dans la transaction même de sa création.
     */
    public function identifiantDeConnexion(): ?string
    {
        $identifiant = $this->ligneDIdentifiant()?->identifiant;

        return $identifiant === null ? null : (string) $identifiant;
    }

    /**
     * Son magasin de rattachement : celui pour lequel son compte de membre
     * a été créé, ou que le propriétaire lui a choisi une fois quand il est
     * devenu membre (voir Identifiant) ; null pour un compte né
     * propriétaire, administrateur ou expert-comptable qui n'a jamais été
     * membre.
     */
    public function magasinDeSonIdentifiant(): ?Magasin
    {
        $magasinId = $this->ligneDIdentifiant()?->magasin_id;

        return $magasinId === null ? null : Magasin::query()->where('societe_id', $this->societe_id)->find($magasinId);
    }

    /**
     * Le calendrier de ses heures propres (sa connexion précédente, l'accès
     * qui lui a été redonné) : celui du magasin de son identifiant pour un
     * membre, qui vit à l'heure de son magasin (voir Magasin::calendrier()),
     * celui de Paris pour un compte de la société (propriétaire,
     * administrateur, expert-comptable).
     */
    public function calendrier(): Calendrier
    {
        return ($this->estMembre() ? $this->magasinDeSonIdentifiant()?->calendrier() : null) ?? Calendrier::deParis();
    }

    /**
     * Est-il un compte d'entraînement : un compte rattaché au magasin de
     * démonstration de sa société, qui n'ouvre jamais un magasin réel et
     * ne change jamais de rôle de société (voir GardesDesDroits) ?
     */
    public function estUnCompteDEntrainement(): bool
    {
        return $this->magasinDeSonIdentifiant()?->est_demonstration ?? false;
    }

    /**
     * La ligne de son identifiant, filtrée par son compte et sa société :
     * la seule lecture d'un identifiant hors de la société active, pour ce
     * compte-ci (voir Identifiant).
     */
    private function ligneDIdentifiant(): ?Identifiant
    {
        return Identifiant::withoutGlobalScopes()
            ->where('societe_id', $this->societe_id)
            ->where('user_id', $this->getKey())
            ->first();
    }

    public function estProprietaire(): bool
    {
        return $this->role_societe === RoleSociete::Proprietaire;
    }

    /**
     * A-t-il une adresse de connexion ? Seul le propriétaire en a une, et
     * pas toujours : tant qu'il n'a pas suivi le lien de l'adresse choisie
     * en saisissant le code du courrier (voir
     * SaisirCodeDeConfirmationAction), et après la clôture de son
     * inscription (voir CloreLInscriptionAction), il ne se connecte qu'avec
     * son identifiant.
     */
    public function aUneAdresseDeConnexion(): bool
    {
        return $this->estProprietaire() && $this->email !== null;
    }

    /**
     * Est-il le propriétaire de la société de ce magasin ?
     */
    public function estProprietaireDuMagasin(Magasin $magasin): bool
    {
        return $this->estProprietaire() && (int) $magasin->societe_id === (int) $this->societe_id;
    }

    public function estAdministrateur(): bool
    {
        return $this->role_societe === RoleSociete::Administrateur;
    }

    public function estExpertComptable(): bool
    {
        return $this->role_societe === RoleSociete::ExpertComptable;
    }

    public function estMembre(): bool
    {
        return $this->role_societe === RoleSociete::Membre;
    }

    /**
     * Est-il responsable de son magasin : un membre dont l'affectation a le
     * rôle de responsable ?
     */
    public function estResponsable(): bool
    {
        return $this->affectationDeMembre()?->role === RoleMagasin::Responsable;
    }

    /**
     * Son rôle dans sa société, tel que le dit le tableau de bord de
     * l'espace d'administration après « Bonjour » : « Propriétaire de »
     * suivi de la dénomination de la société quand elle est connue,
     * sinon le libellé de son rôle (« Administrateur »,
     * « Expert-comptable »).
     */
    public function libelleDuRoleDeSociete(): string
    {
        $denomination = $this->societe?->raison_sociale;

        return $this->estProprietaire() && filled($denomination)
            ? "Propriétaire de {$denomination}"
            : $this->role_societe->getLabel();
    }

    /**
     * Son rôle dans ce magasin, tel que le dit son tableau de bord après
     * « Bonjour » : pour un membre, le rôle de son affectation à ce
     * magasin, suivi du nom du magasin (« Vendeur · Boutique Lyon 1 ») ;
     * pour le propriétaire, un administrateur ou un expert-comptable, son
     * rôle de société (voir libelleDuRoleDeSociete()) ; suivi de
     * « (démonstration) » dans le magasin de démonstration.
     */
    public function libelleDuRoleDans(Magasin $magasin): string
    {
        $libelle = $this->estMembre()
            ? ($this->affectationQuiVautDans($magasin)?->role?->getLabel() ?? RoleSociete::Membre->getLabel()).' · '.$magasin->nom
            : $this->libelleDuRoleDeSociete();

        return $magasin->est_demonstration ? "{$libelle} (démonstration)" : $libelle;
    }

    public function estActif(): bool
    {
        return $this->statut === StatutCompte::Actif;
    }

    public function estSuspendu(): bool
    {
        return $this->statut === StatutCompte::Suspendu;
    }

    public function estRetire(): bool
    {
        return $this->statut === StatutCompte::Retire;
    }

    /**
     * Doit-il choisir son mot de passe à sa connexion, avant toute autre
     * page (voir ExigerSonPropreMotDePasse) ? Un responsable, un
     * administrateur ou un expert-comptable dont le mot de passe est encore
     * son identifiant : à sa première connexion, et à sa connexion suivante
     * quand il vient d'être promu à l'un de ces rôles. Un vendeur ou un
     * observateur le garde tant qu'il le veut.
     */
    public function doitChoisirSonMotDePasse(): bool
    {
        return $this->mot_de_passe_est_l_identifiant
            && ($this->estAdministrateur() || $this->estExpertComptable() || $this->estResponsable());
    }

    /**
     * Les colonnes d'un nouveau statut du compte, donné par cet auteur relu
     * en base, ou par le système (null, la fermeture définitive du magasin
     * de démonstration pour un compte d'entraînement) : qui l'a donné, et
     * si c'était, à cet instant, le propriétaire (voir
     * statutDecideParLeProprietaire()).
     *
     * @return array{statut: StatutCompte, statut_change_par: int|null, statut_decide_par_le_proprietaire: bool}
     */
    public function nouveauStatut(StatutCompte $statut, ?User $auteur): array
    {
        return [
            'statut' => $statut,
            'statut_change_par' => $auteur === null ? null : (int) $auteur->getKey(),
            'statut_decide_par_le_proprietaire' => $auteur?->estProprietaire() ?? false,
        ];
    }

    /**
     * Son statut actuel a-t-il été décidé par le propriétaire de la
     * société, au moment du geste ? Un compte suspendu ou retiré par lui ne
     * se réactive ni ne se réintègre que par le propriétaire, et un compte
     * qu'il a suspendu ne se retire de la société que par le propriétaire
     * (voir PorteeDesDroits) : la décision ne se perd pas quand son auteur
     * transmet ensuite la propriété, et un geste d'un autre ne le devient
     * jamais après coup.
     */
    public function statutDecideParLeProprietaire(): bool
    {
        return $this->statut_decide_par_le_proprietaire;
    }

    /**
     * Le compte peut-il agir ? Actif : sinon il n'a aucun droit, où que ce
     * soit (voir ControleDesDroits).
     */
    public function peutAgir(): bool
    {
        return $this->estActif();
    }

    /**
     * Signe-t-il dans l'application le volet 2 des attestations de
     * l'éditeur (étape 3) ? Seulement le propriétaire qui a validé la
     * société en saisissant le code du courrier recommandé envoyé au nom de
     * son représentant légal (voir ValiderLaSocieteAction) ; un
     * propriétaire devenu propriétaire par une transmission de la
     * propriété signe à la main, même s'il l'avait été avant, sa saisie du
     * code s'effaçant à la transmission (voir
     * AccepterLaTransmissionDeLaProprieteAction).
     */
    public function signeLesAttestationsDansLApplication(): bool
    {
        return $this->estProprietaire() && $this->code_du_courrier_saisi_le !== null;
    }

    /**
     * Ses autorisations de société : toutes pour le propriétaire, celles
     * que le propriétaire a cochées pour un administrateur, aucune pour
     * tout autre compte, même si la base en gardait une ligne. Telles que
     * la base les donne, sans regarder son statut.
     *
     * @return list<string>
     */
    public function autorisationsDeSociete(): array
    {
        if ($this->estProprietaire()) {
            return AutorisationsSociete::toutes();
        }

        if (! $this->estAdministrateur()) {
            return [];
        }

        return DB::table('autorisations_societe')
            ->where('user_id', $this->getKey())
            ->where('societe_id', $this->societe_id)
            ->orderBy('autorisation')
            ->pluck('autorisation')
            ->all();
    }

    /**
     * A-t-il cette autorisation de société (voir AutorisationsSociete) ?
     * Jamais pour un compte qui ne peut pas agir (voir peutAgir()). Elle ne
     * suffit jamais seule pour un geste qui porte sur un magasin : il faut
     * aussi ce magasin dans sa portée (voir administreLeMagasin()).
     */
    public function aLAutorisationDeSociete(string $autorisation): bool
    {
        return $this->peutAgir() && in_array($autorisation, $this->autorisationsDeSociete(), true);
    }

    /**
     * Administre-t-il tous les magasins de sa société, présents et
     * futurs ? Le propriétaire, et un administrateur affecté à tous les
     * magasins, et à aucune sélection (sinon il n'a aucune portée).
     */
    public function administreTousLesMagasins(): bool
    {
        if ($this->estProprietaire()) {
            return true;
        }

        return $this->estAdministrateur()
            && $this->affectationsDAdministrateur()->whereNull('magasin_id')->exists()
            && ! $this->affectations()->whereNotNull('magasin_id')->exists();
    }

    /**
     * Les magasins de sa portée d'administration : tous ceux de sa société
     * pour le propriétaire et un administrateur de tous les magasins, ceux
     * de sa sélection pour un autre administrateur ; aucun pour un
     * administrateur à la fois affecté à tous les magasins et à une
     * sélection, ni pour un membre ou un expert-comptable. Une requête sur
     * les magasins de sa société seulement.
     *
     * @return Builder<Magasin>
     */
    public function magasinsDeSaPortee(): Builder
    {
        $magasins = Magasin::query()->where('magasins.societe_id', $this->societe_id);

        if ($this->administreTousLesMagasins()) {
            return $magasins;
        }

        if (! $this->estAdministrateur() || $this->affectations()->whereNull('magasin_id')->exists()) {
            return $magasins->whereRaw('1 = 0');
        }

        return $magasins->whereIn('magasins.id', $this->affectationsDAdministrateur()->whereNotNull('magasin_id')->select('magasin_id'));
    }

    /**
     * Ce magasin est-il dans sa portée d'administration ?
     */
    public function administreLeMagasin(Magasin $magasin): bool
    {
        return (int) $magasin->societe_id === (int) $this->societe_id
            && $this->magasinsDeSaPortee()->whereKey($magasin->getKey())->exists();
    }

    /**
     * Est-il responsable de ce magasin : un membre dont l'affectation à ce
     * magasin a le rôle de responsable ? Tel que la base le donne, sans
     * regarder son statut.
     */
    public function estResponsableDe(Magasin $magasin): bool
    {
        return $this->estMembre()
            && (int) $magasin->societe_id === (int) $this->societe_id
            && $this->affectations()->where('magasin_id', $magasin->getKey())->where('role', RoleMagasin::Responsable->value)->exists();
    }

    /**
     * Les autorisations du compte dans ce magasin, par ordre alphabétique :
     * aucune dans le magasin d'une autre société, ni pour un compte qui ne
     * peut pas agir (voir peutAgir()) ; sinon celles que son rôle lui donne
     * (voir autorisationsEnregistreesDans()). Lues une fois par instance et
     * par magasin, pour les écrans : toute écriture de droits les fait
     * oublier (voir oublierLesDroits()), et une action les relit sur un
     * compte rechargé (voir LecteurDesDroits).
     *
     * @return list<string>
     */
    public function autorisationsDans(Magasin $magasin): array
    {
        if ((int) $magasin->societe_id !== (int) $this->societe_id || ! $this->peutAgir()) {
            return [];
        }

        return $this->autorisationsParMagasin[$magasin->getKey()] ??= $this->autorisationsEnregistreesDans($magasin) ?? [];
    }

    /**
     * Suit-il les factures à encaisser de ce magasin : l'alerte de son
     * tableau de bord (voir FacturesAEncaisserApercu) et, dans l'espace de
     * la société, les totaux de ce magasin (voir
     * FacturesAEncaisserDeLaSociete) ? Une seule condition pour tous, celle
     * des écrans où elles mènent : il voit les factures (la liste et « Mes
     * ventes » l'exigent, voir FacturePolicy::viewAny()), et, pour un
     * membre, il est autorisé à contre-passer un paiement (un responsable
     * l'est toujours), pour le propriétaire, un administrateur ou un
     * expert-comptable, il voit celles de toute l'équipe. Lue sur ses
     * cases cochées dans ce magasin (voir autorisationsDans()), pas sur le
     * droit d'agir du moment : dans un magasin en lecture seule, où plus
     * rien ne se contre-passe, la facture reste due et l'alerte reste.
     */
    public function suitLesFacturesAEncaisserDans(Magasin $magasin): bool
    {
        $exigees = [PermissionRegistry::FACTURE_VIEW, $this->estMembre() ? PermissionRegistry::PAIEMENT_CONTRE_PASSER : PermissionRegistry::FACTURE_VIEW_TOUTES];

        return array_diff($exigees, $this->autorisationsDans($magasin)) === [];
    }

    /**
     * Tient-il une caisse dans ce magasin : y vend-il (facture.create) ou
     * y encaisse-t-il (paiement.creer) ? Lu sur ses autorisations dans ce
     * magasin (voir autorisationsDans()), pas sur le droit d'agir du
     * moment : dans un magasin en lecture seule, où plus rien ne se vend,
     * chacun ferme toujours sa caisse et consulte ses relevés. Jamais le
     * propriétaire, qui ne vend pas ; un administrateur seulement quand le
     * propriétaire lui a coché la vente.
     */
    public function tientUneCaisseDans(Magasin $magasin): bool
    {
        return array_intersect([PermissionRegistry::FACTURE_CREATE, PermissionRegistry::PAIEMENT_CREER], $this->autorisationsDans($magasin)) !== [];
    }

    /**
     * Oublie les autorisations lues sur cette instance : après une écriture
     * de droits, elles se relisent en base.
     */
    public function oublierLesDroits(): void
    {
        $this->autorisationsParMagasin = [];
    }

    /**
     * Ses droits dans ce magasin, tels que la base les donne, son statut
     * compris, pour le journal des événements (JET 130, voir
     * JournalDesDroits) : son rôle de société, son rôle de magasin (nul
     * pour le propriétaire, un administrateur et un expert-comptable), son
     * statut et ses autorisations par ordre alphabétique ; null quand il
     * n'y en a aucun (magasin d'une autre société, aucun droit qui vaille
     * ici).
     *
     * @return array{role_societe: string, role_magasin: string|null, statut: string, permissions: list<string>}|null
     */
    public function droitsDans(Magasin $magasin): ?array
    {
        $compte = self::query()->findOrFail($this->getKey());

        if ((int) $magasin->societe_id !== (int) $compte->societe_id) {
            return null;
        }

        $autorisations = $compte->autorisationsEnregistreesDans($magasin);

        if ($autorisations === null) {
            return null;
        }

        return [
            'role_societe' => $compte->role_societe->value,
            'role_magasin' => $compte->estMembre() ? $compte->affectationQuiVautDans($magasin)?->role?->value : null,
            'statut' => $compte->statut->value,
            'permissions' => $autorisations,
        ];
    }

    /**
     * L'affectation qui donne ses droits dans ce magasin : pour un membre,
     * celle de ce magasin, avec un rôle ; pour un administrateur qui n'a
     * pas à la fois les deux sortes d'affectations, celle de ce magasin,
     * sinon celle de tous les magasins, sans rôle ; aucune sinon (le
     * propriétaire, un expert-comptable, une affectation qui contredit le
     * rôle du compte).
     */
    public function affectationQuiVautDans(Magasin $magasin): ?Affectation
    {
        if ($this->estMembre()) {
            return $this->affectations()
                ->where('magasin_id', $magasin->getKey())
                ->whereIn('role', array_map(static fn (RoleMagasin $role): string => $role->value, RoleMagasin::cases()))
                ->first();
        }

        if (! $this->estAdministrateur() || $this->aDesAffectationsDesDeuxSortes()) {
            return null;
        }

        return $this->affectationsDAdministrateur()
            ->where(fn (Builder $affectations) => $affectations->where('magasin_id', $magasin->getKey())->orWhereNull('magasin_id'))
            ->first();
    }

    /**
     * Son affectation de membre (avec un rôle de magasin), dans le magasin
     * de son identifiant, lue en base ; aucune pour un compte qui n'est pas
     * membre, ni pour un membre retiré de son magasin.
     */
    public function affectationDeMembre(): ?Affectation
    {
        if (! $this->estMembre()) {
            return null;
        }

        return $this->affectations()->whereNotNull('role')->whereNotNull('magasin_id')->first();
    }

    /**
     * Les autorisations que son rôle lui donne dans ce magasin de sa
     * société, qu'il soit actif ou suspendu ; null quand aucune n'y vaut :
     * - le propriétaire : toutes sauf celles de la vente (voir
     *   PermissionRegistry::duProprietaire()), dans chaque magasin,
     *   démonstration comprise : il ne vend jamais ;
     * - un expert-comptable : les consultations, dans un magasin réel ;
     *   rien dans le magasin de démonstration, ni une fois retiré de la
     *   société (il garde son rôle, sans plus aucun droit, comme un membre
     *   ou un administrateur retiré, dont les affectations ont disparu) ;
     * - un administrateur : les cases de son affectation à ce magasin, ou
     *   de son affectation à tous les magasins ; rien s'il a les deux
     *   sortes d'affectations ;
     * - un membre : toutes pour un responsable de ce magasin, les cases de
     *   son affectation pour un vendeur ou un observateur.
     *
     * @return list<string>|null
     */
    private function autorisationsEnregistreesDans(Magasin $magasin): ?array
    {
        if ($this->estProprietaire()) {
            return PermissionRegistry::duProprietaire();
        }

        if ($this->estExpertComptable()) {
            if ($magasin->est_demonstration || $this->estRetire()) {
                return null;
            }

            $consultations = PermissionRegistry::consultations();
            sort($consultations);

            return $consultations;
        }

        $affectation = $this->affectationQuiVautDans($magasin);

        if ($affectation === null) {
            return null;
        }

        if ($affectation->role !== RoleMagasin::Responsable) {
            return $affectation->autorisations();
        }

        $toutes = PermissionRegistry::toutes();
        sort($toutes);

        return $toutes;
    }

    /**
     * Les affectations sans rôle, celles d'un administrateur.
     *
     * @return HasMany<Affectation, $this>
     */
    private function affectationsDAdministrateur(): HasMany
    {
        return $this->affectations()->whereNull('role');
    }

    /**
     * Un administrateur à la fois affecté à tous les magasins et à une
     * sélection : la base le refuse (voir GardesDesDroits), et ses droits
     * se lisent comme nuls.
     */
    private function aDesAffectationsDesDeuxSortes(): bool
    {
        return $this->affectations()->whereNull('magasin_id')->exists()
            && $this->affectations()->whereNotNull('magasin_id')->exists();
    }

    /**
     * Peut-il administrer sa société (son espace d'administration) ? Son
     * propriétaire et ses administrateurs, avec un compte actif ; jamais
     * un membre ni un expert-comptable.
     */
    public function peutAdministrer(): bool
    {
        return $this->estActif() && ($this->estProprietaire() || $this->estAdministrateur());
    }

    /**
     * Peut-il ouvrir ce magasin ? Un magasin de sa société, avec un compte
     * qui peut agir (voir peutAgir()) : le propriétaire ouvre tous ceux de
     * sa société ; un expert-comptable, tous ses magasins réels, jamais la
     * démonstration ; tout autre compte, ceux où une affectation lui donne
     * des droits (voir affectationQuiVautDans()).
     */
    public function peutOuvrir(Magasin $magasin): bool
    {
        if ((int) $magasin->societe_id !== (int) $this->societe_id || ! $this->peutAgir()) {
            return false;
        }

        if ($this->estProprietaire()) {
            return true;
        }

        if ($this->estExpertComptable()) {
            return ! $magasin->est_demonstration;
        }

        return $this->affectationQuiVautDans($magasin) !== null;
    }

    /**
     * Les magasins qu'il peut ouvrir, par nom, le magasin de démonstration
     * à part, après les autres (voir peutOuvrir()) ; aucun pour un compte
     * qui n'est pas actif ; pour un membre, son seul magasin.
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsAccessibles(): Collection
    {
        if (! $this->estActif()) {
            return new Collection;
        }

        $magasins = Magasin::query()->where('societe_id', $this->societe_id);

        if ($this->estExpertComptable()) {
            $magasins->where('est_demonstration', false);
        } elseif ($this->estMembre()) {
            $magasins->whereIn('id', $this->affectations()->whereNotNull('role')->whereNotNull('magasin_id')->select('magasin_id'));
        } elseif ($this->estAdministrateur()) {
            $magasins->whereIn('id', $this->magasinsDeSaPortee()->select('magasins.id'));
        }

        return $magasins->orderBy('est_demonstration')->orderBy('nom')->orderBy('id')->get();
    }

    /**
     * Efface toutes ses notifications : le jour où il est suspendu ou
     * retiré de sa société, rien ne lui en reste dans sa cloche.
     */
    public function effacerToutesSesNotifications(): void
    {
        $this->notifications()->delete();
    }

    /**
     * Efface ses notifications à propos de ces magasins (voir
     * NotificationDuMagasin) le jour où il n'y a plus accès : il ne voit
     * plus rien d'eux dans sa cloche.
     *
     * @param  list<int>  $magasins
     */
    public function effacerLesNotificationsDesMagasins(array $magasins): void
    {
        foreach ($magasins as $magasin) {
            $this->notifications()->where('data->magasin_id', $magasin)->delete();
        }
    }

    /**
     * Filament\Models\Contracts\HasTenants : les magasins que le panneau
     * « app » lui ouvre (voir magasinsAccessibles()), et sa société dans
     * celui de l'administration de la société s'il peut l'administrer.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        return match ($panel->getId()) {
            'app' => $this->magasinsAccessibles(),
            'societe' => $this->peutAdministrer() ? new Collection([$this->societe]) : new Collection,
            default => new Collection,
        };
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return match (true) {
            $tenant instanceof Magasin => $this->peutOuvrir($tenant),
            $tenant instanceof Societe => $tenant->getKey() === (int) $this->societe_id && $this->peutAdministrer(),
            default => false,
        };
    }

    /**
     * Sans cette implémentation explicite, Filament se rabat sur
     * `config('app.env') === 'local'` pour autoriser l'accès à un panneau :
     * l'accès ne doit jamais dépendre de l'environnement. Le panneau des
     * magasins s'ouvre à tout compte actif : celui qui n'a encore aucun
     * magasin à ouvrir y est mené à l'espace de sa société, s'il peut
     * l'administrer, sinon à une page qui le lui dit (voir
     * MenerUnCompteSansMagasin), et aucun magasin ne s'ouvre hors de ceux
     * que lui donne canAccessTenant(). Celui de l'administration de la
     * société s'ouvre à son propriétaire et à ses administrateurs actifs. Un
     * compte qui doit choisir son mot de passe n'y voit que ce choix (voir
     * ExigerSonPropreMotDePasse). Tout autre panneau
     * reste fermé, jusqu'à ce que l'administration de l'éditeur (étape 13
     * de la feuille de route) définisse le sien.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'app' => $this->estActif(),
            'societe' => $this->peutAdministrer(),
            default => false,
        };
    }
}

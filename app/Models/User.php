<?php

namespace App\Models;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\RefuseUneCleIllisible;
use App\Domain\Tenancy\Enums\ModeDAccesRedonne;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use Database\Factories\UserFactory;
use Filament\Facades\Filament;
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
 * rôle dans la société (voir RoleSociete) ; un compte d'un cabinet
 * d'expertise comptable appartient au cabinet et n'entre chez un client
 * que par le lien de son cabinet (voir AccesDesCabinets) :
 * - son propriétaire, qui supervise et contrôle : tous les droits dans la
 *   société et dans chacun de ses magasins, sans case ni affectation, sauf
 *   celui de vendre, qu'il n'a dans aucun magasin réel (voir
 *   PermissionRegistry::duProprietaire()), seulement dans le magasin de
 *   démonstration tant qu'il est ouvert (voir
 *   PermissionRegistry::duProprietaireEnDemonstration()) ;
 * - un administrateur, avec ses autorisations de société et sa portée
 *   (tous les magasins ou une sélection : ses affectations sans rôle, qui
 *   portent ses cases) ;
 * - dans un cabinet d'expertise comptable, qui n'a ni administrateur ni
 *   membre, un collaborateur, créé par le propriétaire du cabinet, sans
 *   affectation, ni case, ni autorisation de société (voir
 *   CreerCollaborateurAction) ;
 * - un membre, dont le compte ne vaut que pour le magasin de son identifiant (voir
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
 * que la personne change quand elle le veut ; un responsable ou un
 * administrateur le change à sa première connexion (voir
 * doitChoisirSonMotDePasse()), un collaborateur d'un cabinet le choisit
 * à la création de son compte (voir CreerCollaborateurAction). Seul un
 * compte actif agit (voir peutAgir()).
 *
 * Un compte ne s'efface pas : retiré, il garde son rôle, son identifiant
 * et son historique. Son adresse e-mail est l'adresse de connexion du
 * propriétaire, confirmée par un lien et unique parmi les propriétaires
 * (voir aUneAdresseDeConnexion()), ou un simple contact pour tout autre
 * compte.
 *
 * Sa société n'est jamais nulle : societe_id ne l'est jamais en base, sa
 * clé étrangère vise une société, qu'aucune société ne s'efface (voir
 * DeclencheursDeProtection::sansSuppression()) et que Societe n'a aucun
 * scope global.
 *
 * @property-read Societe $societe
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'nouvelle_adresse_jeton_hache', 'jeton_acces_redonne'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, RefuseUneCleIllisible;

    /**
     * La longueur des colonnes où l'historique fige le nom d'une personne
     * (les colonnes *_nom, auteur_libelle) : un nom pour l'historique ne la
     * dépasse jamais (voir nomPourLHistorique()).
     */
    public const LONGUEUR_D_UN_NOM_POUR_L_HISTORIQUE = 255;

    /**
     * Les caractères de la dénomination d'un cabinet gardés au moins dans
     * le nom pour l'historique d'un de ses comptes, quand il faut le
     * raccourcir.
     */
    private const DENOMINATION_GARDEE_DU_CABINET = 40;

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

    /**
     * Pour un compte d'un cabinet d'expertise comptable, les magasins que
     * lui ouvrent ses liens (voir peutOuvrir()), lus une fois par
     * instance, comme ses autorisations : oubliés à chaque écriture de
     * droits, à chaque refresh() et à la fin de chaque requête (voir
     * AppServiceProvider), la fin d'un lien ou le retrait d'un magasin de la
     * sélection du client fermant l'accès dès la requête suivante.
     *
     * @var list<int>|null
     */
    private ?array $magasinsOuverts = null;

    /**
     * Pour un compte d'un cabinet, les magasins que ses liens lui ouvrent,
     * sans regarder l'état du cabinet (voir
     * AccesDesCabinets::idsDesMagasinsDuCompte()), où valent les
     * autorisations que le client accorde : lus et oubliés de même.
     *
     * @var list<int>|null
     */
    private ?array $magasinsDesLiens = null;

    /**
     * Pour un compte d'un cabinet, les autorisations que chaque client lui
     * accorde (voir AccesDesCabinets::autorisationsParClient()), lues une
     * fois par instance et oubliées de même.
     *
     * @var array<int, list<string>>|null
     */
    private ?array $autorisationsParClient = null;

    /**
     * Pour un expert-comptable de la société, sa déclaration (voir
     * ExpertsComptablesDeclares::declarationDe()), lue une fois par
     * instance et oubliée de même ; faux tant qu'elle n'est pas lue.
     */
    private LienCabinet|false|null $declarationLue = false;

    /**
     * Pour un expert-comptable de la société, les autorisations de sa
     * déclaration, lues une fois par instance et oubliées de même.
     *
     * @var list<string>|null
     */
    private ?array $autorisationsDeLaDeclaration = null;

    /**
     * Ses affectations, lues une fois par instance pour les écrans (voir
     * affectationQuiVautDans()), et les autorisations cochées de chacune
     * (voir autorisationsEnregistreesDans()) : un administrateur de tous
     * les magasins a la même affectation dans chacun, et une page qui
     * parcourt les magasins de sa portée ne la relit pas pour chacun.
     * Oubliées avec ses autorisations.
     *
     * @var Collection<int, Affectation>|null
     */
    private ?Collection $affectationsLues = null;

    /**
     * @var array<int, list<string>>
     */
    private array $autorisationsParAffectation = [];

    protected function casts(): array
    {
        return [
            'societe_id' => 'integer',
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
            'acces_redonne_mode' => ModeDAccesRedonne::class,
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
     * @return Attribute<string|null, string|null>
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
     * propriétaire, administrateur ou collaborateur qui n'a jamais été
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
     * administrateur, collaborateur d'un cabinet).
     */
    public function calendrier(): Calendrier
    {
        return $this->estMembre() ? ValeurRequise::de($this->magasinDeSonIdentifiant(), 'le magasin de l\'identifiant du membre')->calendrier() : Calendrier::deParis();
    }

    /**
     * Est-il un compte d'entraînement : un compte rattaché au magasin de
     * démonstration de sa société, qui n'ouvre jamais un magasin réel et
     * ne change jamais de rôle de société (voir GardesDesDroits) ?
     */
    public function estUnCompteDEntrainement(): bool
    {
        return $this->magasinDeSonIdentifiant()->est_demonstration ?? false;
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
        return $this->estProprietaire() && $magasin->societe_id === $this->societe_id;
    }

    public function estAdministrateur(): bool
    {
        return $this->role_societe === RoleSociete::Administrateur;
    }

    /**
     * Est-il un compte d'un cabinet d'expertise comptable (voir
     * Societe::estUnCabinet()) : son propriétaire ou l'un de ses
     * collaborateurs ? Lu sur sa société, chargée une fois par instance.
     */
    public function estDUnCabinet(): bool
    {
        return $this->societe->estUnCabinet();
    }

    public function estMembre(): bool
    {
        return $this->role_societe === RoleSociete::Membre;
    }

    /**
     * Est-il un collaborateur d'un cabinet d'expertise comptable (voir
     * RoleSociete::Collaborateur) ?
     */
    public function estCollaborateur(): bool
    {
        return $this->role_societe === RoleSociete::Collaborateur;
    }

    /**
     * Est-il un expert-comptable de la société, déclaré par le SIREN de son
     * cabinet (voir RoleSociete::ExpertComptable) ?
     */
    public function estExpertComptable(): bool
    {
        return $this->role_societe === RoleSociete::ExpertComptable;
    }

    /**
     * Entre-t-il dans les magasins d'une société par les autorisations
     * qu'elle accorde à un cabinet (voir PermissionRegistry::duCabinet()) :
     * un compte d'un cabinet lié, ou un expert-comptable de la société ?
     * Ses droits n'y sont alors que ces autorisations, un refus net hors
     * d'elles (voir ControleDesDroits).
     */
    public function entreParLesAutorisationsDUnCabinet(): bool
    {
        return $this->estExpertComptable() || $this->estDUnCabinet();
    }

    /**
     * Pour un expert-comptable de la société, la déclaration à laquelle son
     * compte est attaché, lue une fois par instance, quel que soit son
     * état ; null pour tout autre compte.
     */
    public function declarationDeLExpertComptable(): ?LienCabinet
    {
        if (! $this->estExpertComptable()) {
            return null;
        }

        if ($this->declarationLue === false) {
            $this->declarationLue = app(ExpertsComptablesDeclares::class)->declarationDe($this);
        }

        return $this->declarationLue;
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
     * l'espace d'administration ou de l'espace du cabinet après
     * « Bonjour » : « Propriétaire de » suivi de la dénomination de la
     * société quand elle est connue (« Propriétaire du cabinet » pour un
     * cabinet d'expertise comptable), « Collaborateur du cabinet » suivi
     * de sa dénomination pour un collaborateur, « Expert-comptable déclaré
     * par la société · cabinet » suivi du cabinet déclaré pour un
     * expert-comptable de la société, sinon le libellé de son rôle
     * (« Administrateur »).
     */
    public function libelleDuRoleDeSociete(): string
    {
        $denomination = $this->societe->raison_sociale;

        return match (true) {
            $this->estProprietaire() && $this->estDUnCabinet() => filled($denomination) ? "Propriétaire du cabinet {$denomination}" : 'Propriétaire du cabinet',
            $this->estCollaborateur() => filled($denomination) ? "Collaborateur du cabinet {$denomination}" : RoleSociete::Collaborateur->getLabel(),
            $this->estExpertComptable() => 'Expert-comptable déclaré par la société · '.($this->declarationDeLExpertComptable()?->nomDuCabinetDeclare() ?? 'cabinet'),
            $this->estProprietaire() && filled($denomination) => "Propriétaire de {$denomination}",
            default => $this->role_societe->getLabel(),
        };
    }

    /**
     * Son rôle dans ce magasin, tel que le dit son tableau de bord après
     * « Bonjour » : pour un membre, le rôle de son affectation à ce
     * magasin, suivi du nom du magasin (« Vendeur · Boutique Lyon 1 ») ;
     * pour un compte d'un cabinet d'expertise comptable chez un client, son
     * rôle dans son cabinet suivi du nom du magasin (« Collaborateur du
     * cabinet Martin · Boutique Lyon 1 ») ; pour le propriétaire ou un
     * administrateur, son rôle de société (voir
     * libelleDuRoleDeSociete()) ; suivi de « (démonstration) » dans le
     * magasin de démonstration.
     */
    public function libelleDuRoleDans(Magasin $magasin): string
    {
        $libelle = match (true) {
            $this->estMembre() => $this->libelleDuRoleDeMembreDans($magasin).' · '.$magasin->nom,
            $this->entreParLesAutorisationsDUnCabinet() => $this->libelleDuRoleDeSociete().' · '.$magasin->nom,
            default => $this->libelleDuRoleDeSociete(),
        };

        return $magasin->est_demonstration ? "{$libelle} (démonstration)" : $libelle;
    }

    /**
     * Le libellé de son rôle de membre dans ce magasin : celui de son
     * affectation, qui en a toujours un (voir affectationQuiVautDans()),
     * ou « Membre » sans affectation dans ce magasin.
     */
    private function libelleDuRoleDeMembreDans(Magasin $magasin): string
    {
        $affectation = $this->affectationQuiVautDans($magasin);

        return $affectation === null ? RoleSociete::Membre->getLabel() : ValeurRequise::de($affectation->role, 'le rôle de l\'affectation d\'un membre')->getLabel();
    }

    /**
     * Son nom tel que l'historique le garde (le nom figé d'un auteur, le
     * registre fiscal, les journaux) : son nom ; pour un compte d'un
     * cabinet d'expertise comptable, qui agit chez un client en son nom,
     * avec celui de son cabinet : « Nom (cabinet Dénomination) », ou le
     * SIREN du cabinet sans dénomination ; pour un expert-comptable de la
     * société, avec ce qui le rattache au cabinet déclaré, sans laisser
     * croire que ce cabinet l'a reconnu, d'après la dénomination ou le
     * SIREN lus sur sa déclaration, quel que soit son état (voir
     * nomAvecSonCabinet()). Borné à 255 caractères : la dénomination se
     * raccourcit d'abord, en gardant au moins ses 40
     * premiers caractères, puis le nom ; seul un texte vraiment raccourci
     * finit par « … ».
     */
    public function nomPourLHistorique(): string
    {
        if ($this->estExpertComptable()) {
            $declaration = $this->declarationDeLExpertComptable();

            return $declaration === null ? $this->name : self::nomAvecSonCabinet($this->name, '(expert-comptable déclaré par la société, cabinet ', filled($declaration->denomination_cabinet) ? (string) $declaration->denomination_cabinet : 'SIREN '.$declaration->siren_cabinet);
        }

        if (! $this->estDUnCabinet()) {
            return $this->name;
        }

        return self::nomAvecSonCabinet($this->name, '(cabinet ', filled($this->societe->raison_sociale) ? (string) $this->societe->raison_sociale : (string) $this->societe->siren);
    }

    /**
     * Le nom suivi de son cabinet entre parenthèses, après cette
     * ouverture : « Marc Petit (cabinet COMPTA RHONE) » pour un compte d'un
     * cabinet, « Marc Petit (expert-comptable déclaré par la société,
     * cabinet CABINET PETIT ET ASSOCIES) » pour un expert-comptable de la
     * société, borné à 255 caractères selon la règle de
     * nomPourLHistorique().
     */
    private static function nomAvecSonCabinet(string $nom, string $ouverture, string $cabinet): string
    {
        $place = self::LONGUEUR_D_UN_NOM_POUR_L_HISTORIQUE - mb_strlen(" {$ouverture})");
        $raccourci = static fn (string $texte, int $longueur): string => mb_strlen($texte) <= $longueur ? $texte : mb_substr($texte, 0, $longueur - 1).'…';
        $cabinet = $raccourci($cabinet, max(self::DENOMINATION_GARDEE_DU_CABINET, $place - mb_strlen($nom)));
        $nom = $raccourci($nom, $place - mb_strlen($cabinet));

        return "{$nom} {$ouverture}{$cabinet})";
    }

    /**
     * Le nom pour l'historique (voir nomPourLHistorique()) du compte de cet
     * identifiant, relu en base avec sa société, null s'il n'existe pas.
     */
    public static function nomFigeDe(int $id): ?string
    {
        return self::query()->with('societe')->find($id)?->nomPourLHistorique();
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
     * page (voir ExigerSonPropreMotDePasse) ? Un responsable ou un
     * administrateur dont le mot de passe est encore son identifiant : à
     * sa première connexion, et à sa connexion suivante quand il vient
     * d'être promu à l'un de ces rôles. Un vendeur ou un observateur le
     * garde tant qu'il le veut.
     */
    public function doitChoisirSonMotDePasse(): bool
    {
        return $this->mot_de_passe_est_l_identifiant
            && ($this->estAdministrateur() || $this->estResponsable());
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
            'statut_change_par' => $auteur === null ? null : CleDuModele::de($auteur),
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
     * Ses autorisations de société : toutes pour le propriétaire (les
     * seuls paramètres, son identité, pour le propriétaire d'un cabinet
     * d'expertise comptable, voir
     * AutorisationsSociete::duProprietaireDUnCabinet()), celles que le
     * propriétaire a cochées pour un administrateur, aucune pour tout autre
     * compte, même si la base en gardait une ligne. Telles que la base les
     * donne, sans regarder son statut.
     *
     * @return list<string>
     */
    public function autorisationsDeSociete(): array
    {
        if ($this->estProprietaire()) {
            return $this->estDUnCabinet() ? AutorisationsSociete::duProprietaireDUnCabinet() : AutorisationsSociete::toutes();
        }

        if (! $this->estAdministrateur()) {
            return [];
        }

        return ValeursLues::textes(DB::table('autorisations_societe')
            ->where('user_id', $this->getKey())
            ->where('societe_id', $this->societe_id)
            ->orderBy('autorisation')
            ->pluck('autorisation'));
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
     * sélection, ni pour un membre ou un collaborateur. Une requête sur
     * les magasins de sa société seulement.
     *
     * @return Builder<Magasin>
     */
    public function magasinsDeSaPortee(): Builder
    {
        $magasins = Magasin::query()->with('societe')->where('magasins.societe_id', $this->societe_id);

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
        return $magasin->societe_id === $this->societe_id
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
            && $magasin->societe_id === $this->societe_id
            && $this->affectations()->where('magasin_id', $magasin->getKey())->where('role', RoleMagasin::Responsable->value)->exists();
    }

    /**
     * Les autorisations du compte dans ce magasin, par ordre alphabétique :
     * aucune dans le magasin d'une autre société, sauf pour un compte d'un
     * cabinet d'expertise comptable chez un client lié qui lui est ouvert,
     * ni pour un compte qui ne peut pas agir (voir peutAgir()) ; sinon
     * celles que son rôle ou le lien de son cabinet lui donne (voir
     * autorisationsEnregistreesDans()). Lues une fois par instance et
     * par magasin, pour les écrans : toute écriture de droits les fait
     * oublier (voir oublierLesDroits()), et une action les relit sur un
     * compte rechargé (voir LecteurDesDroits).
     *
     * @return list<string>
     */
    public function autorisationsDans(Magasin $magasin): array
    {
        if (! $this->peutAgir() || ($magasin->societe_id !== $this->societe_id && ! $this->estDUnCabinet())) {
            return [];
        }

        return $this->autorisationsParMagasin[CleDuModele::de($magasin)] ??= $this->autorisationsEnregistreesDans($magasin) ?? [];
    }

    /**
     * Suit-il toutes les factures à encaisser de ce magasin, pour en lire
     * les totaux hors du magasin : dans l'espace de la société (voir
     * FacturesAEncaisserDeLaSociete) et au tableau de bord d'un cabinet
     * d'expertise comptable (voir TableauDeBordDesClients) ? Il y voit les
     * factures et celles de toute l'équipe (toujours le propriétaire, les
     * cases cochées pour un administrateur, les autorisations que le
     * client accorde pour un compte d'un cabinet), et peut donc ouvrir la
     * liste où mène chaque total. Lue sur ses cases cochées dans ce
     * magasin (voir autorisationsDans()), pas sur le droit d'agir du
     * moment : dans un magasin en lecture seule, la facture reste due.
     * Dans le magasin, l'« À faire » de son tableau de bord a sa propre
     * règle (voir ChosesAFaireDuMagasin).
     */
    public function suitToutesLesFacturesAEncaisserDe(Magasin $magasin): bool
    {
        return array_diff([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES], $this->autorisationsDans($magasin)) === [];
    }

    /**
     * Tient-il une caisse dans ce magasin : y vend-il (facture.create) ou
     * y encaisse-t-il (paiement.creer) ? Lu sur ses autorisations dans ce
     * magasin (voir autorisationsDans()), pas sur le droit d'agir du
     * moment : dans un magasin en lecture seule, où plus rien ne se vend,
     * chacun ferme toujours sa caisse et consulte ses relevés. Le
     * propriétaire seulement dans le magasin de démonstration tant qu'il
     * est ouvert, le seul où il vend ; un administrateur seulement quand le
     * propriétaire lui a coché la vente.
     */
    public function tientUneCaisseDans(Magasin $magasin): bool
    {
        return array_intersect([PermissionRegistry::FACTURE_CREATE, PermissionRegistry::PAIEMENT_CREER], $this->autorisationsDans($magasin)) !== [];
    }

    /**
     * Oublie les autorisations lues sur cette instance, et sa société, où
     * se lit s'il est un compte d'un cabinet (voir estDUnCabinet()) : après
     * une écriture de droits, elles se relisent en base.
     */
    public function oublierLesDroits(): void
    {
        $this->autorisationsParMagasin = [];
        $this->magasinsOuverts = null;
        $this->magasinsDesLiens = null;
        $this->autorisationsParClient = null;
        $this->declarationLue = false;
        $this->autorisationsDeLaDeclaration = null;
        $this->affectationsLues = null;
        $this->autorisationsParAffectation = [];
        $this->unsetRelation('societe');
    }

    /**
     * Ses droits dans ce magasin, tels que la base les donne, son statut
     * compris, pour le journal des événements (JET 130, voir
     * JournalDesDroits) : son rôle de société, son rôle de magasin (nul
     * pour le propriétaire, un administrateur et un compte d'un cabinet),
     * son statut et ses autorisations par ordre alphabétique, et, pour un
     * compte d'un cabinet d'expertise comptable chez un client lié, son
     * cabinet (identifiant, SIREN, dénomination), et, pour un
     * expert-comptable de la société, son cabinet déclaré (SIREN,
     * dénomination lue au répertoire) ;
     * null quand il n'y en a aucun (magasin d'une autre société hors d'un
     * client lié, aucun droit qui vaille ici).
     *
     * @return array{role_societe: string, role_magasin: string|null, statut: string, permissions: list<string>, cabinet?: array{id: int, siren: string, raison_sociale: string|null}, cabinet_declare?: array{siren: string, raison_sociale: string|null}}|null
     */
    public function droitsDans(Magasin $magasin): ?array
    {
        $compte = self::query()->whereKey($this->getKey())->firstOrFail();
        $duCabinet = $compte->estDUnCabinet();

        if ($magasin->societe_id !== $compte->societe_id && ! $duCabinet) {
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
            ...($duCabinet ? ['cabinet' => IdentiteDansLeJournal::de($compte->societe)] : []),
            ...($compte->estExpertComptable() ? ['cabinet_declare' => IdentiteDansLeJournal::declaree(ValeurRequise::de($compte->declarationDeLExpertComptable(), 'la déclaration d\'un expert-comptable de la société'))] : []),
        ];
    }

    /**
     * L'affectation qui donne ses droits dans ce magasin : pour un membre,
     * celle de ce magasin, avec un rôle ; pour un administrateur qui n'a
     * pas à la fois les deux sortes d'affectations, celle de ce magasin,
     * sinon celle de tous les magasins, sans rôle ; aucune sinon (le
     * propriétaire, un compte d'un cabinet, une affectation qui contredit
     * le rôle du compte).
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

        return $this->affectationsLues()->first(fn (Affectation $affectation): bool => $affectation->role === null
            && ($affectation->magasin_id === null || $affectation->magasin_id === $magasin->getKey()));
    }

    /**
     * Son affectation de membre (avec un rôle de magasin), dans le magasin
     * de son identifiant, lue en base ; aucune pour un compte qui n'est pas
     * membre, ni pour un membre retiré de son magasin.
     */
    public function affectationDeMembre(): ?Affectation
    {
        return $this->estMembre() ? $this->affectations()->whereNotNull('role')->whereNotNull('magasin_id')->first() : null;
    }

    /**
     * Les autorisations que son rôle lui donne dans ce magasin de sa
     * société, qu'il soit actif ou suspendu, toujours croisées avec celles
     * du type d'activité de la société (voir
     * PermissionRegistry::pourLeType()) : une case d'une autre famille ne
     * s'y lit jamais ; null quand aucune n'y vaut :
     * - un compte d'un cabinet d'expertise comptable, dans un magasin réel
     *   d'un client lié que son cabinet lui ouvre (son propriétaire, ou un
     *   collaborateur à qui ce client est confié), parmi ceux que le client
     *   ouvre au cabinet (tous, ou sa sélection, voir
     *   AccesDesCabinets::idsDesMagasinsDuCompte()) : celles que le client
     *   accorde au cabinet (voir AccesDesCabinets::autorisationsParClient()),
     *   sans regarder si le cabinet est vérifié, ce que contrôlent
     *   peutOuvrir() et ControleDesDroits, comme la lecture seule ;
     * - un expert-comptable de la société, dans un magasin réel de sa
     *   société que sa déclaration en attente lui ouvre (tous, ou la
     *   sélection du propriétaire) : les autorisations de sa déclaration
     *   (voir ExpertsComptablesDeclares) ;
     * - le propriétaire : toutes sauf celles de la vente dans chaque
     *   magasin réel, où il ne vend jamais, comme dans le magasin de
     *   démonstration fermé (voir PermissionRegistry::duProprietaire()), et
     *   toutes, vente comprise, dans le magasin de démonstration tant qu'il
     *   est ouvert (voir PermissionRegistry::duProprietaireEnDemonstration()) :
     *   sa fermeture et sa réouverture s'inscrivent à son JET 130 (voir
     *   JournalDesDroits::autourDuChangementDeStatut()) ;
     * - un administrateur : les cases de son affectation à ce magasin, ou
     *   de son affectation à tous les magasins ; rien s'il a les deux
     *   sortes d'affectations ;
     * - un membre : pour un responsable de ce magasin, toutes sauf celles
     *   que le propriétaire est seul à cocher (voir
     *   PermissionRegistry::cocheesParLeProprietaire()), plus celles-ci
     *   quand le propriétaire les lui a cochées ; les cases de son
     *   affectation pour un vendeur ou un observateur.
     *
     * @return list<string>|null
     */
    private function autorisationsEnregistreesDans(Magasin $magasin): ?array
    {
        if ($this->estExpertComptable()) {
            return in_array(CleDuModele::de($magasin), $this->magasinsDesLiens ??= app(ExpertsComptablesDeclares::class)->idsDesMagasinsOuverts($this), true)
                ? $this->autorisationsDeLaDeclaration ??= ValeurRequise::de($this->declarationDeLExpertComptable(), 'la déclaration d\'un expert-comptable de la société')->autorisationsRetenues()
                : null;
        }

        if ($this->estDUnCabinet()) {
            return in_array(CleDuModele::de($magasin), $this->magasinsDesLiens ??= app(AccesDesCabinets::class)->idsDesMagasinsDuCompte($this), true)
                ? ($this->autorisationsParClient ??= app(AccesDesCabinets::class)->autorisationsParClient($this))[$magasin->societe_id] ?? null
                : null;
        }

        $duType = PermissionRegistry::pourLeType($magasin->societe->type_activite);

        if ($this->estProprietaire()) {
            return $magasin->est_demonstration && ! $magasin->estFerme()
                ? PermissionRegistry::duProprietaireEnDemonstration($magasin->societe->type_activite)
                : PermissionRegistry::duProprietaire($magasin->societe->type_activite);
        }

        $affectation = $this->affectationQuiVautDans($magasin);

        if ($affectation === null) {
            return null;
        }

        $cochees = $this->autorisationsParAffectation[CleDuModele::de($affectation)] ??= $affectation->autorisations();

        return $affectation->role !== RoleMagasin::Responsable
            ? self::trieesDans($cochees, $duType)
            : self::trieesDans([...array_diff($duType, PermissionRegistry::cocheesParLeProprietaire($magasin->societe->type_activite)), ...$cochees], $duType);
    }

    /**
     * Ces autorisations, celles de ce type seulement, dans l'ordre
     * alphabétique.
     *
     * @param  list<string>  $autorisations
     * @param  list<string>  $duType
     * @return list<string>
     */
    private static function trieesDans(array $autorisations, array $duType): array
    {
        $retenues = array_values(array_intersect($autorisations, $duType));
        sort($retenues);

        return $retenues;
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
        return $this->affectationsLues()->whereNull('magasin_id')->isNotEmpty()
            && $this->affectationsLues()->whereNotNull('magasin_id')->isNotEmpty();
    }

    /**
     * @return Collection<int, Affectation>
     */
    private function affectationsLues(): Collection
    {
        return $this->affectationsLues ??= $this->affectations()->orderBy('id')->get();
    }

    /**
     * Peut-il administrer sa société (son espace d'administration) ? Son
     * propriétaire et ses administrateurs, avec un compte actif ; jamais
     * un membre, ni un compte d'un cabinet d'expertise comptable, qui a
     * son propre espace (voir CabinetPanelProvider).
     */
    public function peutAdministrer(): bool
    {
        return $this->estActif() && ($this->estProprietaire() || $this->estAdministrateur()) && ! $this->estDUnCabinet();
    }

    /**
     * Peut-il ouvrir ce magasin ? Avec un compte qui peut agir (voir
     * peutAgir()), un compte d'un cabinet d'expertise comptable vérifié
     * ouvre les magasins réels d'un client lié qui lui est ouvert, tous ou
     * ceux que le client a choisis (voir
     * AccesDesCabinets::idsDesMagasinsOuverts()) ; un expert-comptable de
     * la société, les magasins réels de sa société que sa déclaration en
     * attente lui ouvre ; tout autre compte, un magasin de sa
     * société : le propriétaire ouvre tous ceux de sa société ; tout autre
     * compte, ceux où une affectation lui donne des droits (voir
     * affectationQuiVautDans()).
     */
    public function peutOuvrir(Magasin $magasin): bool
    {
        if ($this->estExpertComptable()) {
            return $this->peutAgir() && $magasin->societe_id === $this->societe_id
                && in_array(CleDuModele::de($magasin), $this->magasinsOuverts ??= app(ExpertsComptablesDeclares::class)->idsDesMagasinsOuverts($this), true);
        }

        if ($this->estDUnCabinet()) {
            return $this->peutAgir()
                && in_array(CleDuModele::de($magasin), $this->magasinsOuverts ??= app(AccesDesCabinets::class)->idsDesMagasinsOuverts($this), true);
        }

        if ($magasin->societe_id !== $this->societe_id || ! $this->peutAgir()) {
            return false;
        }

        if ($this->estProprietaire()) {
            return true;
        }

        return $this->affectationQuiVautDans($magasin) !== null;
    }

    /**
     * Les magasins qu'il peut ouvrir, par nom, le magasin de démonstration
     * à part, après les autres (voir peutOuvrir()) ; aucun pour un compte
     * qui n'est pas actif ; pour un membre, son seul magasin ; pour un
     * compte d'un cabinet d'expertise comptable, les magasins réels des
     * clients que son cabinet lui ouvre, par dénomination du client puis
     * nom du magasin (voir AccesDesCabinets::magasinsOuverts()) ; pour un
     * expert-comptable de la société, les magasins réels que sa déclaration
     * lui ouvre, par nom.
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsAccessibles(): Collection
    {
        if (! $this->estActif()) {
            return new Collection;
        }

        if ($this->estDUnCabinet()) {
            return app(AccesDesCabinets::class)->magasinsOuverts($this);
        }

        if ($this->estExpertComptable()) {
            return Magasin::query()->with('societe')->whereKey(app(ExpertsComptablesDeclares::class)->idsDesMagasinsOuverts($this))->orderBy('nom')->orderBy('id')->get();
        }

        $magasins = Magasin::query()->with('societe')->where('societe_id', $this->societe_id);

        if ($this->estMembre()) {
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
     * Les magasins de sa société où ses droits valent, quel que soit son
     * statut, par identifiant : ceux de sa portée pour le propriétaire et
     * un administrateur (voir magasinsDeSaPortee()), celui de son
     * affectation pour un membre, aucun pour un compte d'un cabinet. Relus
     * en base : après un changement de ses droits, ceux qui sortent de la
     * liste s'effacent de sa cloche (voir effacerLesNotificationsDesMagasins()).
     *
     * @return list<int>
     */
    public function magasinsDeSesDroits(): array
    {
        return ValeursLues::entiers($this->estMembre()
            ? $this->affectations()->whereNotNull('magasin_id')->pluck('magasin_id')
            : $this->magasinsDeSaPortee()->pluck('magasins.id'));
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
     * Efface ses avis de cabinet à propos de ces clients (voir AvisDuCabinet,
     * données client_id) le jour où ils ne lui sont plus ouverts : il ne
     * voit plus rien d'eux dans sa cloche.
     *
     * @param  list<int>  $clients
     */
    public function effacerLesAvisDesClients(array $clients): void
    {
        foreach ($clients as $client) {
            $this->notifications()->where('data->client_id', $client)->delete();
        }
    }

    /**
     * Filament\Models\Contracts\HasTenants : les magasins que le panneau
     * « app » lui ouvre (voir magasinsAccessibles()) ; pour un compte d'un
     * cabinet d'expertise comptable, seulement ceux du client du magasin
     * ouvert, s'il lui est ouvert, jamais tous les clients de son cabinet ;
     * sa société dans celui de l'administration de la société s'il peut
     * l'administrer, et son cabinet dans l'espace du cabinet pour un compte
     * actif d'un cabinet d'expertise comptable.
     *
     * @return Collection<int, Magasin>|Collection<int, Societe>
     */
    public function getTenants(Panel $panel): Collection
    {
        return match ($panel->getId()) {
            'app' => $this->estDUnCabinet() ? $this->magasinsDuClientOuvert() : $this->magasinsAccessibles(),
            'societe' => $this->peutAdministrer() ? new Collection([$this->societe]) : new Collection,
            'cabinet' => $this->ouvreLEspaceDuCabinet() ? new Collection([$this->societe]) : new Collection,
            default => new Collection,
        };
    }

    /**
     * Pour un compte d'un cabinet d'expertise comptable, les magasins qu'il
     * ouvre du client du magasin ouvert dans le panneau des magasins, s'il
     * l'ouvre ; aucun sinon.
     *
     * @return Collection<int, Magasin>
     */
    private function magasinsDuClientOuvert(): Collection
    {
        $ouvert = Filament::getTenant();

        if (! $ouvert instanceof Magasin || ! $this->peutOuvrir($ouvert)) {
            return new Collection;
        }

        return $this->magasinsAccessibles()->where('societe_id', $ouvert->societe_id)->values();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return match (true) {
            $tenant instanceof Magasin => $this->peutOuvrir($tenant),
            $tenant instanceof Societe => $tenant->getKey() === $this->societe_id && ($this->peutAdministrer() || $this->ouvreLEspaceDuCabinet()),
            default => false,
        };
    }

    /**
     * Ouvre-t-il l'espace de son cabinet (voir CabinetPanelProvider) ? Un
     * compte actif d'un cabinet d'expertise comptable.
     */
    public function ouvreLEspaceDuCabinet(): bool
    {
        return $this->estActif() && $this->estDUnCabinet();
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
     * société s'ouvre à son propriétaire et à ses administrateurs actifs,
     * jamais à un cabinet d'expertise comptable ; celui du cabinet, aux
     * comptes actifs d'un cabinet (voir ouvreLEspaceDuCabinet()). Un
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
            'cabinet' => $this->ouvreLEspaceDuCabinet(),
            default => false,
        };
    }
}

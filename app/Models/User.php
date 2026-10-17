<?php

namespace App\Models;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
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
 * - son propriétaire, qui a tous les droits dans la société et dans chacun
 *   de ses magasins, sans case ni affectation ;
 * - un administrateur, avec ses autorisations de société et sa portée
 *   (tous les magasins ou une sélection : ses affectations sans rôle, qui
 *   portent ses cases) ;
 * - un expert-comptable, sans affectation ni case, qui lit chaque magasin
 *   réel de la société (voir PermissionRegistry::consultations()) ;
 * - un membre, affecté à un ou plusieurs magasins avec un rôle de magasin
 *   dans chacun : responsable (toutes les autorisations de ce magasin,
 *   sans case), vendeur ou observateur (ses cases dans ce magasin).
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
 * Un compte créé par la société a un mot de passe provisoire, valable 72
 * heures, que la personne change avant toute autre page ; jusque-là,
 * comme un compte suspendu ou retiré, il n'a aucun droit (voir peutAgir()).
 * La personne peut le refuser tant que son adresse n'a jamais été
 * confirmée (voir peutRefuserSonCompte()).
 *
 * Un compte ne s'efface pas : son adresse ne sert jamais à un autre
 * compte (index unique de users.email).
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'nouvelle_adresse_jeton_hache'])]
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
        'mot_de_passe_a_changer' => false,
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
            'email_verified_at' => 'datetime',
            'qualite_verifiee_le' => 'datetime',
            'password' => 'hashed',
            'role_societe' => RoleSociete::class,
            'statut' => StatutCompte::class,
            'statut_decide_par_le_proprietaire' => 'boolean',
            'mot_de_passe_a_changer' => 'boolean',
            'mot_de_passe_provisoire_expire_le' => 'datetime',
            'nouvelle_adresse_expire_le' => 'datetime',
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
     * même adresse, qui ne sert jamais à un second compte (index unique de
     * users.email). La connexion et « Mot de passe oublié » la cherchent
     * ainsi (voir FournisseurDesComptes), et les formulaires la valident
     * déjà sous cette forme (voir la macro adresseDeConnexion()).
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
     * Qui a créé ce compte pour la personne (voir RefuserSonCompteAction,
     * qui le prévient, et PorteeDesDroits, qui lui réserve les nouveaux
     * droits d'un compte au mot de passe provisoire).
     *
     * @return BelongsTo<User, $this>
     */
    public function createur(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cree_par');
    }

    public function estProprietaire(): bool
    {
        return $this->role_societe === RoleSociete::Proprietaire;
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
     * Son mot de passe est-il provisoire ? La personne le change avant
     * toute autre page (voir ExigerUnMotDePasseDefinitif).
     */
    public function doitChangerSonMotDePasse(): bool
    {
        return $this->mot_de_passe_a_changer;
    }

    /**
     * Peut-il recevoir de cet auteur de nouveaux droits : un nouveau
     * magasin, un rôle de responsable, une réintégration, un rôle
     * d'administrateur ? Toujours s'il a choisi son mot de passe ; tant que
     * son mot de passe est un provisoire, seulement de la personne qui a
     * créé le compte, et seulement si c'est elle qui lui a donné ce
     * provisoire (users.provisoire_donne_par) : qui connaît le provisoire
     * ne profite jamais, par ce compte, de droits qu'il ne pourrait pas
     * donner lui-même, même quand un autre que l'auteur du compte le lui a
     * redonné (voir DonnerUnMotDePasseProvisoireAction).
     */
    public function recoitDeNouveauxDroitsDe(User $auteur): bool
    {
        return ! $this->doitChangerSonMotDePasse()
            || ((int) $this->cree_par === (int) $auteur->getKey() && (int) $this->provisoire_donne_par === (int) $auteur->getKey());
    }

    /**
     * Les colonnes d'un nouveau statut du compte, donné par cet auteur relu
     * en base (le compte lui-même pour un refus ou la création de sa
     * société) : qui l'a donné, et si c'était, à cet instant, le
     * propriétaire (voir statutDecideParLeProprietaire()).
     *
     * @return array{statut: StatutCompte, statut_change_par: int, statut_decide_par_le_proprietaire: bool}
     */
    public function nouveauStatut(StatutCompte $statut, User $auteur): array
    {
        return [
            'statut' => $statut,
            'statut_change_par' => (int) $auteur->getKey(),
            'statut_decide_par_le_proprietaire' => $auteur->estProprietaire(),
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
     * Son statut actuel vient-il de son propre refus (voir
     * RefuserSonCompteAction) ? Il ne revient alors que par un
     * rattachement qu'il accepte.
     */
    public function estRetireParSonRefus(): bool
    {
        return $this->estRetire() && (int) $this->statut_change_par === (int) $this->getKey();
    }

    /**
     * Son mot de passe provisoire a-t-il dépassé ses 72 heures ? Il n'ouvre
     * plus le compte : la personne en demande un nouveau à qui l'a créé.
     */
    public function sonMotDePasseProvisoireAExpire(): bool
    {
        return $this->mot_de_passe_a_changer && $this->mot_de_passe_provisoire_expire_le?->isPast() === true;
    }

    /**
     * Son adresse est-elle confirmée ? users.email_verified_at est la date
     * à laquelle quelqu'un a suivi un lien envoyé à l'adresse actuelle du
     * compte, et rien d'autre : le lien de l'inscription (voir
     * CreerSocieteAction), la décision prise sur la page d'un lien « Mot de
     * passe oublié » (voir PageDuLien), l'acceptation d'un rattachement
     * (voir AccepterUnRattachementAction) ou la confirmation d'une nouvelle
     * adresse (voir ConfirmerNouvelleAdresseAction). Aucune création par
     * la société ne la pose, ni le changement d'un mot de passe provisoire.
     */
    public function aConfirmeSonAdresse(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * La personne peut-elle refuser ce compte ? Jamais le propriétaire ;
     * tout autre compte (membre, administrateur, expert-comptable) dont
     * l'adresse n'a jamais été confirmée (voir aConfirmeSonAdresse()) et
     * qui est actif, suspendu, ou retiré par la société (par une autre
     * personne que lui) : un compte retiré par son propre refus ne se
     * refuse pas une seconde fois. Les actions relisent la condition en
     * base, sous le verrou de la ligne du compte (voir
     * RefuserSonCompteAction).
     */
    public function peutRefuserSonCompte(): bool
    {
        if ($this->estProprietaire() || $this->aConfirmeSonAdresse()) {
            return false;
        }

        return match ($this->statut) {
            StatutCompte::Actif, StatutCompte::Suspendu => true,
            StatutCompte::Retire => $this->statut_change_par !== null && (int) $this->statut_change_par !== (int) $this->getKey(),
        };
    }

    /**
     * Le compte peut-il agir ? Actif, avec un mot de passe définitif :
     * sinon il n'a aucun droit, où que ce soit (voir ControleDesDroits).
     */
    public function peutAgir(): bool
    {
        return $this->estActif() && ! $this->doitChangerSonMotDePasse();
    }

    /**
     * Sa qualité de représentant légal ou de mandataire de sa société
     * est-elle prouvée, pour lui-même (voir ValiderLaSocieteAction) ? Elle
     * ne passe jamais d'un compte à un autre.
     */
    public function aSaQualiteVerifiee(): bool
    {
        return $this->qualite_verifiee_le !== null;
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
     * Ses affectations de membre (avec un rôle de magasin), lues en base ;
     * aucune pour un compte qui n'est pas membre.
     *
     * @return Collection<int, Affectation>
     */
    public function affectationsDeMembre(): Collection
    {
        if (! $this->estMembre()) {
            return new Collection;
        }

        return $this->affectations()->whereNotNull('role')->whereNotNull('magasin_id')->orderBy('magasin_id')->get();
    }

    /**
     * Les autorisations que son rôle lui donne dans ce magasin de sa
     * société, quel que soit son statut ; null quand aucune n'y vaut :
     * - le propriétaire : toutes ;
     * - un expert-comptable : les consultations, dans un magasin réel ;
     *   rien dans le magasin de démonstration ;
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
        $toutes = PermissionRegistry::toutes();
        sort($toutes);

        if ($this->estProprietaire()) {
            return $toutes;
        }

        if ($this->estExpertComptable()) {
            if ($magasin->est_demonstration) {
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

        return $affectation->role === RoleMagasin::Responsable ? $toutes : $affectation->autorisations();
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
     * qui n'est pas actif. Un compte au mot de passe provisoire les voit,
     * pour que le panneau s'ouvre sur le changement de son mot de passe,
     * mais n'en ouvre aucun avant.
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
     * Efface toutes ses notifications : le jour où il quitte sa société
     * (retiré, rattaché à une autre), rien ne lui en reste dans sa cloche.
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
     * compte au mot de passe provisoire n'y voit que le changement de son
     * mot de passe (voir ExigerUnMotDePasseDefinitif). Tout autre panneau
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

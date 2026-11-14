<?php

namespace App\Domain\Cabinets\Models;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\ValeursLues;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Le lien entre un cabinet d'expertise comptable et un client (voir
 * EtatDuLien) ; sa société est le client, qui accorde l'accès. Un client
 * n'a qu'un lien actif à la fois (index unique partiel) ; un lien actif
 * ouvre au cabinet tous les magasins réels du client, présents et futurs,
 * ou ceux qu'il choisit (voir magasinsChoisis() et choisirLesMagasins()),
 * et porte les autorisations que le client accorde au cabinet, les mêmes
 * dans chacun de ces magasins (voir autorisations() et accorder()), que
 * le cabinet répartit entre ses
 * collaborateurs par les clients qu'il leur confie (voir ClientConfie).
 * Le lien de l'e-mail d'une demande de rattachement porte un jeton dont
 * seule l'empreinte est gardée, tant que la demande est en cours.
 *
 * Il porte aussi la déclaration de l'expert-comptable d'une société
 * validée (voir DeclarerSonExpertComptableAction) : un lien sans cabinet,
 * d'état declare, avec le SIREN déclaré et la dénomination que le
 * répertoire Sirene lui donne, ses autorisations et son choix de magasins,
 * pour les comptes de l'expert-comptable de la société (voir
 * CompteExpertComptable) ; la même ligne reçoit son cabinet et devient le
 * lien actif quand un cabinet vérifié de ce SIREN s'y rattache (voir
 * RattacherUneDeclarationAction). Le lien en cours d'un client est son
 * lien actif ou sa déclaration : il en a au plus un.
 *
 * Lectures hors de la société active, chacune filtrée : depuis le cabinet,
 * ses liens (voir scopeDuCabinet()) ; avant qu'aucune société ne soit
 * ouverte, la demande de rattachement que désigne le lien de son e-mail
 * (voir parSonJeton()) ; quand un cabinet devient vérifié, l'identifiant et
 * la société des déclarations en attente de son SIREN (voir
 * scopeDeclaresDuSiren()).
 *
 * Son client n'est jamais nul : c'est sa société (societe_id, jamais nul
 * en base), que sa clé étrangère vise sans jamais la laisser s'effacer,
 * et Societe n'a aucun scope global (voir BelongsToSociete).
 *
 * @property int|null $cabinet_id
 * @property string|null $siren_cabinet
 * @property string|null $denomination_cabinet
 * @property bool $tous_les_magasins
 * @property EtatDuLien $etat
 * @property OrigineDuLien $origine
 * @property-read Societe $client
 */
class LienCabinet extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    public const DUREE_D_UNE_DEMANDE_EN_JOURS = 7;

    public const MESSAGE_SELECTION_VIDE = 'Choisissez tous les magasins ou au moins un magasin.';

    protected $table = 'liens_cabinet';

    protected $fillable = [];

    protected $hidden = ['jeton_hache'];

    protected $casts = [
        'cabinet_id' => 'integer',
        'etat' => EtatDuLien::class,
        'origine' => OrigineDuLien::class,
        'motif_fin' => MotifDeFinDuLien::class,
        'demande_le' => Instant::class,
        'expire_le' => Instant::class,
        'repondu_le' => Instant::class,
        'actif_depuis' => Instant::class,
        'termine_le' => Instant::class,
        'tous_les_magasins' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (LienCabinet $lien): void {
            $lien->uuid ??= (string) Str::uuid();
        });
    }

    protected static function colonnesAuNomFige(): array
    {
        return ['demande_par' => 'demande_par_nom', 'repondu_par' => 'repondu_par_nom', 'termine_par' => 'termine_par_nom'];
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    /**
     * Un lien se désigne dans une adresse par son uuid, jamais par son
     * identifiant numérique : l'adresse de la fiche d'un client du cabinet
     * que produisent sa table, ses actions et ses avis est celle que la
     * ressource lit (voir ClientDuCabinetResource::resolveRecordRouteBinding()).
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Celle de cet uuid, venu d'une adresse ou d'un geste de l'écran : un
     * texte qui n'est pas un uuid ne désigne rien, sans interroger la
     * colonne, de type uuid.
     *
     * @param  Builder<self>  $requete
     * @return Builder<self>
     */
    public function scopeParUuid(Builder $requete, string $uuid): Builder
    {
        return Str::isUuid($uuid) ? $requete->where('liens_cabinet.uuid', $uuid) : $requete->whereRaw('1 = 0');
    }

    /**
     * La demande de rattachement en cours que désigne ce jeton, hors de
     * toute société (la page du lien s'ouvre avant qu'aucune ne le soit),
     * expirée ou non ; null pour un jeton inconnu ou une demande qui n'est
     * plus en cours. Seulement ce que la page lit : jamais rien de la
     * société visée, qui ne se montre qu'à son propriétaire connecté.
     */
    public static function parSonJeton(string $jeton): ?self
    {
        return self::withoutGlobalScopes()
            ->where('jeton_hache', self::empreinte($jeton))
            ->where('etat', EtatDuLien::DemandeParLeCabinet->value)
            ->first(['id', 'uuid', 'societe_id', 'cabinet_id', 'etat', 'expire_le']);
    }

    /**
     * Les liens de ce cabinet, lus depuis le cabinet : la seule façon de
     * les lister hors de la société du client, sans SocieteScope et
     * filtrés par le cabinet.
     *
     * @param  Builder<self>  $requete
     * @return Builder<self>
     */
    public function scopeDuCabinet(Builder $requete, Societe|int $cabinet): Builder
    {
        return $requete->withoutGlobalScope(SocieteScope::class)
            ->where('liens_cabinet.cabinet_id', $cabinet instanceof Societe ? $cabinet->getKey() : $cabinet);
    }

    /**
     * @return BelongsTo<Societe, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Societe::class, 'societe_id');
    }

    /**
     * @return BelongsTo<Societe, $this>
     */
    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Societe::class, 'cabinet_id');
    }

    public function estActif(): bool
    {
        return $this->etat === EtatDuLien::Actif;
    }

    /**
     * Une déclaration de l'expert-comptable de la société, en attente de
     * son cabinet.
     */
    public function estDeclare(): bool
    {
        return $this->etat === EtatDuLien::Declare;
    }

    /**
     * Le lien en cours du client : actif ou déclaré.
     */
    public function estEnCours(): bool
    {
        return $this->etat->estEnCours();
    }

    /**
     * Le lien en cours du client (voir EtatDuLien::enCours()).
     *
     * @param  Builder<self>  $requete
     * @return Builder<self>
     */
    public function scopeEnCours(Builder $requete): Builder
    {
        return $requete->whereIn('liens_cabinet.etat', array_map(static fn (EtatDuLien $etat): string => $etat->value, EtatDuLien::enCours()));
    }

    /**
     * Les déclarations en attente de ce SIREN, lues hors de toute société
     * quand le cabinet de ce SIREN devient vérifié : leur identifiant et
     * leur société seulement, par identifiant croissant.
     *
     * @return Collection<int, self>
     */
    public static function declaresDuSiren(string $siren): Collection
    {
        return self::query()->withoutGlobalScope(SocieteScope::class)
            ->where('siren_cabinet', $siren)
            ->where('etat', EtatDuLien::Declare->value)
            ->orderBy('id')
            ->get(['id', 'societe_id']);
    }

    /**
     * Le cabinet déclaré, tel que les écrans, les e-mails et le nom figé
     * des comptes de l'expert-comptable de la société le nomment :
     * « cabinet » suivi de la dénomination lue au répertoire, ou de
     * « SIREN … » quand le répertoire la masque.
     */
    public function nomDuCabinetDeclare(): string
    {
        return 'cabinet '.(filled($this->denomination_cabinet) ? (string) $this->denomination_cabinet : 'SIREN '.$this->siren_cabinet);
    }

    public function estUneDemande(): bool
    {
        return $this->etat->estUneDemande();
    }

    /**
     * Une demande en cours, pas encore expirée.
     */
    public function estUneDemandeValable(): bool
    {
        return $this->estUneDemande() && $this->expire_le !== null && $this->expire_le->isFuture();
    }

    /**
     * L'instant où une demande refusée, retirée ou expirée a pris fin : sa
     * réponse, ou son échéance pour une demande expirée, même si la tâche
     * horaire ne l'a expirée qu'ensuite ; null pour tout autre lien. Le
     * délai avant une nouvelle demande de la même partie part de là (voir
     * DesignerUnCabinetAction et InviterUneEntrepriseAction).
     */
    public function finDeLaDemande(): ?CarbonImmutable
    {
        return match ($this->etat) {
            EtatDuLien::Expire => $this->expire_le,
            EtatDuLien::Refuse, EtatDuLien::Retire => $this->repondu_le,
            default => null,
        };
    }

    /**
     * Les demandes refusées, retirées ou expirées après cet instant (voir
     * finDeLaDemande()).
     *
     * @param  Builder<LienCabinet>  $requete
     */
    public function scopeCloseApres(Builder $requete, CarbonImmutable $instant): void
    {
        $requete->where(fn (Builder $closes) => $closes
            ->where(fn (Builder $repondues) => $repondues->whereIn('etat', [EtatDuLien::Refuse->value, EtatDuLien::Retire->value])->where('repondu_le', '>', $instant))
            ->orWhere(fn (Builder $expirees) => $expirees->where('etat', EtatDuLien::Expire->value)->where('expire_le', '>', $instant)));
    }

    /**
     * Les magasins réels que le client a choisis d'ouvrir à son cabinet pour
     * ce lien, par identifiant croissant, lus dans la société du client ;
     * aucun quand il les lui ouvre tous, présents et futurs (voir
     * tous_les_magasins).
     *
     * @return list<int>
     */
    public function magasinsChoisis(): array
    {
        return ValeursLues::entiers(DB::table('magasins_cabinet')
            ->where('societe_id', $this->societe_id)
            ->where('lien_id', $this->getKey())
            ->orderBy('magasin_id')
            ->pluck('magasin_id'));
    }

    /**
     * Ouvre au cabinet tous les magasins réels du client, présents et
     * futurs (null), ou seulement ceux-ci, dans la transaction de
     * l'appelant (décision de l'éditeur du 11/11/2026) : une sélection vide
     * est refusée, comme un magasin qui n'est pas un magasin réel du client
     * (le magasin de démonstration, ou celui d'une autre société) ; la base
     * refuse elle aussi ces sélections (voir GardesDesCabinets).
     *
     * @param  array<array-key, mixed>|null  $magasins
     *
     * @throws InvalidArgumentException
     */
    public function choisirLesMagasins(?array $magasins): void
    {
        $choisis = $magasins === null ? [] : array_values(array_unique(array_map(static fn (mixed $magasin): int => is_numeric($magasin) ? (int) $magasin : 0, $magasins)));
        sort($choisis);

        if ($magasins !== null && $choisis === []) {
            throw new InvalidArgumentException(self::MESSAGE_SELECTION_VIDE);
        }

        $reels = ValeursLues::entiers(DB::table('magasins')->where('societe_id', $this->societe_id)->where('est_demonstration', false)->whereIn('id', $choisis)->pluck('id'));

        if (array_diff($choisis, $reels) !== []) {
            throw new InvalidArgumentException('Seuls les magasins réels de votre société s\'ouvrent à votre cabinet.');
        }

        DB::table('magasins_cabinet')->where('societe_id', $this->societe_id)->where('lien_id', $this->getKey())->delete();
        $this->forceFill(['tous_les_magasins' => $magasins === null])->save();
        DB::table('magasins_cabinet')->insert(array_map(fn (int $magasin): array => [
            'societe_id' => $this->societe_id,
            'lien_id' => $this->getKey(),
            'magasin_id' => $magasin,
        ], $choisis));
    }

    /**
     * Les autorisations que le client accorde au cabinet pour ce lien, par
     * ordre alphabétique, lues dans la société du client.
     *
     * @return list<string>
     */
    public function autorisations(): array
    {
        return ValeursLues::textes(DB::table('autorisations_cabinet')
            ->where('societe_id', $this->societe_id)
            ->where('lien_id', $this->getKey())
            ->orderBy('autorisation')
            ->pluck('autorisation'));
    }

    /**
     * Les autorisations de ce lien qui valent pour le type d'activité du
     * client (voir PermissionRegistry::duCabinet()), par ordre alphabétique
     * : celles d'un compte d'un cabinet lié comme celles d'un
     * expert-comptable de la société, lues dans la société du client.
     *
     * @return list<string>
     */
    public function autorisationsRetenues(): array
    {
        $retenues = array_values(array_intersect($this->autorisations(), PermissionRegistry::duCabinet(Societe::query()->findOrFail($this->societe_id)->type_activite)));
        sort($retenues);

        return $retenues;
    }

    /**
     * Remplace les autorisations accordées au cabinet pour ce lien, dans la
     * transaction de l'appelant, après les avoir vérifiées pour le type
     * d'activité du client (voir PermissionRegistry::verifierDuCabinet()) ;
     * la base refuse elle aussi une autorisation qui ne s'accorde pas.
     *
     * @param  array<array-key, mixed>  $autorisations
     *
     * @throws InvalidArgumentException
     */
    public function accorder(array $autorisations): void
    {
        $retenues = PermissionRegistry::verifierDuCabinet($autorisations, Societe::query()->findOrFail($this->societe_id)->type_activite);

        DB::table('autorisations_cabinet')->where('societe_id', $this->societe_id)->where('lien_id', $this->getKey())->delete();
        DB::table('autorisations_cabinet')->insert(array_map(fn (string $autorisation): array => [
            'societe_id' => $this->societe_id,
            'lien_id' => $this->getKey(),
            'autorisation' => $autorisation,
        ], $retenues));
    }
}

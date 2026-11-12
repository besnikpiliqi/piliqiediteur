<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Calendrier\Casts\JourCivil;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\OrigineDesAchats;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Un téléchargement des documents par période (décision de l'éditeur du
 * 03/11/2026, lot EX) : la demande d'un compte, dans la société dont il
 * lit les documents (la sienne, ou le client lié de son cabinet), préparée
 * dans la file d'attente (voir PreparerLeTelechargementDesDocumentsJob),
 * puis servie par son lien, pendant sept jours, à ce seul compte, toujours
 * autorisé à ce moment (voir TelechargerLesDocumentsController).
 *
 * Les jours de sa période (voir JourCivil) ne sont jamais nuls : leurs
 * colonnes ne le sont pas en base.
 *
 * Son échéance (voir Instant) n'est jamais nulle : sa colonne ne l'est pas
 * en base.
 *
 * Son demandeur n'est jamais nul : demande_par ne l'est jamais en base, et
 * sa clé étrangère vise un compte qui ne s'efface pas tant qu'elle le
 * vise ; User n'a aucun scope global.
 *
 * @property string $du
 * @property string $au
 * @property CarbonImmutable $expire_le
 * @property-read User $demandeur
 */
class TelechargementDeDocuments extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    /**
     * Combien de temps son lien vaut.
     */
    public const JOURS_DE_VALIDITE = 7;

    /**
     * Combien de téléchargements un compte demande au plus par heure.
     */
    public const PAR_HEURE_AU_PLUS = 10;

    protected $table = 'telechargements_documents';

    protected $fillable = [];

    protected $hidden = ['chemin'];

    protected $casts = [
        'magasins' => 'array',
        'du' => JourCivil::class,
        'au' => JourCivil::class,
        'types' => 'array',
        'origine' => OrigineDesAchats::class,
        'piece_manquante_seulement' => 'boolean',
        'format' => FormatDuTelechargement::class,
        'selection' => 'array',
        'pieces' => 'array',
        'statut' => StatutDuTelechargement::class,
        'nombre_documents' => 'integer',
        'taille' => 'integer',
        'prepare_le' => Instant::class,
        'expire_le' => Instant::class,
    ];

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'demande_par' => 'demande_par_nom',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TelechargementDeDocuments $telechargement): void {
            $telechargement->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Le téléchargement de ce lien demandé par ce compte, quelle que soit la
     * société active (un compte d'un cabinet le demande pour un client) :
     * une lecture hors de la société courante, filtrée par le compte qui
     * l'a demandé ; un texte qui n'est pas un uuid ne désigne rien.
     *
     * @return Builder<static>
     */
    public static function duLienPour(User $compte, string $uuid): Builder
    {
        return static::query()
            ->withoutGlobalScope(SocieteScope::class)
            ->where('demande_par', $compte->getKey())
            ->when(Str::isUuid($uuid), fn (Builder $telechargements): Builder => $telechargements->where('uuid', $uuid), fn (Builder $telechargements): Builder => $telechargements->whereRaw('1 = 0'));
    }

    /**
     * Les téléchargements que ce compte a demandés depuis cet instant, dans
     * toutes les sociétés : la limite par heure se compte par compte (voir
     * DemanderLeTelechargementDesDocumentsAction).
     *
     * @return Builder<static>
     */
    public static function demandesDepuis(User $compte, mixed $instant): Builder
    {
        return static::query()
            ->withoutGlobalScope(SocieteScope::class)
            ->where('demande_par', $compte->getKey())
            ->where('created_at', '>', $instant);
    }

    /**
     * Les téléchargements que ce compte a demandés dans cette société depuis
     * sept jours, les plus récents d'abord, dix au plus : ceux que montre
     * la page « Télécharger les documents ». Une lecture filtrée par la
     * société et par le compte, quelle que soit la société active (un
     * compte d'un cabinet les demande pour un client).
     *
     * @return Builder<static>
     */
    public static function recentsDe(User $compte, Societe $societe): Builder
    {
        return static::query()
            ->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $societe->getKey())
            ->where('demande_par', $compte->getKey())
            ->where('expire_le', '>', now())
            ->orderByDesc('id')
            ->limit(10);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demande_par');
    }

    /**
     * Les types demandés.
     *
     * @return list<TypeDeDocumentTelechargeable>
     */
    public function typesDemandes(): array
    {
        return array_values(array_filter(array_map(fn (mixed $type): ?TypeDeDocumentTelechargeable => is_string($type) ? TypeDeDocumentTelechargeable::tryFrom($type) : null, $this->types ?? [])));
    }

    /**
     * Les clés des documents choisis (voir DocumentTrouve::cle()), telles
     * que la demande les a gardées : des textes, que seul le code y écrit.
     *
     * @return list<string>
     *
     * @throws UnexpectedValueException une valeur qui n'est pas un texte
     */
    public function clesChoisies(): array
    {
        return ValeursLues::textes($this->selection ?? []);
    }

    /**
     * Les identifiants des pièces du fournisseur que le fichier prêt
     * contient (voir ArchiveDesDocuments::ecrire()), tels que la
     * préparation les a gardés : des entiers, que seul le code y écrit.
     *
     * @return list<int>
     *
     * @throws UnexpectedValueException une valeur qui n'est pas un entier
     */
    public function piecesContenues(): array
    {
        return ValeursLues::entiers($this->pieces ?? []);
    }

    public function estExpire(): bool
    {
        return $this->expire_le->lessThanOrEqualTo(now());
    }

    /**
     * L'adresse de son lien, dans le panneau de la personne qui l'a
     * demandé : l'espace de son cabinet pour un compte d'un cabinet,
     * l'espace de la société pour le propriétaire ou un administrateur, le
     * panneau des magasins pour tout autre compte (voir
     * TelechargerLesDocumentsController).
     */
    public function lien(): string
    {
        $compte = $this->demandeur;
        $panneau = match (true) {
            $compte->estDUnCabinet() => 'cabinet',
            $compte->peutAdministrer() => 'societe',
            default => 'app',
        };

        return route("filament.{$panneau}.telechargements-de-documents.telecharger", ['telechargement' => $this->uuid]);
    }

    /**
     * Le nom sous lequel le fichier se télécharge.
     */
    public function nomDuFichier(): string
    {
        return 'documents-'.$this->du.'-au-'.$this->au.'.'.$this->format->value;
    }
}

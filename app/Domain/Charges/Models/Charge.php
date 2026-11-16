<?php

namespace App\Domain\Charges\Models;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Calendrier\Casts\JourCivil;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\NatureDeCharge;
use App\Domain\Charges\Enums\OrigineDeLaCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Services\TypesDeCharge;
use App\Domain\Charges\Support\TextesDesCharges;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Une charge de la société (décision de l'éditeur du 14/11/2026, lot
 * CHARGES) : le loyer, l'électricité, les honoraires… d'un mois, de
 * « Toute la société » ou d'un de ses magasins réels, avec sa pièce (voir
 * PieceDeCharge) et ses lignes par taux (voir LigneDeCharge), pour la TVA
 * réelle et estimée de la société (voir TvaDeLaPeriode). Elle ne devient
 * jamais un achat de marchandises, ne crée jamais de produit et ne touche
 * jamais le stock. Elle naît « pièce sans chiffres » ou « à valider »
 * déposée par le client, « validée » ajoutée par son comptable ; le
 * comptable la valide, la renvoie au client avec un mot, ou la corrige
 * après sa validation, avec un motif ; avant sa validation, qui l'a
 * déposée ou le comptable la supprime (voir les actions de
 * App\Domain\Charges\Actions et DroitsSurLesCharges). Validée, elle ne
 * s'efface jamais et ne change que par une correction (voir
 * GardesDesCharges). Ses montants sont en centimes.
 *
 * @property string $uuid
 * @property int|null $magasin_id
 * @property TypeDeCharge|null $type
 * @property int|null $type_de_la_societe_id
 * @property string|null $fournisseur
 * @property string|null $numero_piece
 * @property string $date_piece
 * @property string|null $date_paiement
 * @property string $mois_tva
 * @property bool $mois_tva_fixe
 * @property bool $fournisseur_hors_france
 * @property bool|null $vehicule_utilitaire
 * @property string|null $note
 * @property EtatDeLaCharge $etat
 * @property OrigineDeLaCharge $origine
 * @property int $total_ht
 * @property int $total_tva
 * @property int $total_ttc
 * @property int $total_tva_deductible
 * @property bool $tva_a_verifier
 * @property int $deposee_par
 * @property string $deposee_par_nom
 * @property ValideePar|null $validee_par
 * @property string|null $validee_par_nom
 * @property string|null $motif_renvoi
 * @property string|null $renvoyee_par_nom
 * @property int $corrections
 */
class Charge extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    protected $table = 'charges';

    protected $fillable = [];

    protected $casts = [
        'magasin_id' => 'integer',
        'type' => TypeDeCharge::class,
        'type_de_la_societe_id' => 'integer',
        'date_piece' => JourCivil::class,
        'date_paiement' => JourCivil::class,
        'mois_tva_fixe' => 'boolean',
        'fournisseur_hors_france' => 'boolean',
        'vehicule_utilitaire' => 'boolean',
        'etat' => EtatDeLaCharge::class,
        'origine' => OrigineDeLaCharge::class,
        'total_ht' => 'integer',
        'total_tva' => 'integer',
        'total_ttc' => 'integer',
        'total_tva_deductible' => 'integer',
        'tva_a_verifier' => 'boolean',
        'deposee_par' => 'integer',
        'validee_par' => ValideePar::class,
        'validee_par_compte' => 'integer',
        'validee_le' => Instant::class,
        'renvoyee_par' => 'integer',
        'renvoyee_le' => Instant::class,
        'corrections' => 'integer',
        'corrigee_le' => Instant::class,
    ];

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'deposee_par' => 'deposee_par_nom',
            'validee_par_compte' => 'validee_par_nom',
            'renvoyee_par' => 'renvoyee_par_nom',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Charge $charge): void {
            $charge->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Une charge se désigne dans une adresse ou un geste par son uuid,
     * jamais par son identifiant.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Celle de cet uuid : un texte qui n'est pas un uuid ne désigne rien,
     * sans interroger la base, où PostgreSQL le refuserait.
     *
     * @param  Builder<Charge>  $requete
     * @return Builder<Charge>
     */
    public function scopeParUuid(Builder $requete, string $uuid): Builder
    {
        return Str::isUuid($uuid) ? $requete->where('uuid', $uuid) : $requete->whereRaw('1 = 0');
    }

    /**
     * Ses lignes de la version en vigueur, par rang.
     *
     * @return HasMany<LigneDeCharge, $this>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDeCharge::class)->orderBy('rang');
    }

    /**
     * @return HasOne<PieceDeCharge, $this>
     */
    public function piece(): HasOne
    {
        return $this->hasOne(PieceDeCharge::class);
    }

    /**
     * @return BelongsTo<Magasin, $this>
     */
    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }

    /**
     * @return BelongsTo<TypeDeChargeDeLaSociete, $this>
     */
    public function typeDeLaSociete(): BelongsTo
    {
        return $this->belongsTo(TypeDeChargeDeLaSociete::class, 'type_de_la_societe_id');
    }

    /**
     * Le nom de son type : celui de la liste de PILIQI, ou celui que la
     * société a donné au sien.
     */
    public function libelleDuType(): string
    {
        return $this->type?->getLabel() ?? ValeursLues::texteOuNul($this->getAttributes()['type_nom'] ?? null) ?? (string) $this->typeDeLaSociete?->nom;
    }

    /**
     * La clé de son type dans le tableau des mois (voir
     * ChargesDeLaSociete::tableauDesMois()) : « piliqi:loyer », ou
     * « societe: » suivi de l'uuid d'un type de la société, lu avec la
     * charge par ChargesDeLaSociete::liste() quand elle l'a lue ainsi.
     */
    public function cleDuType(): string
    {
        return $this->type !== null
            ? TypesDeCharge::PILIQI.$this->type->value
            : TypesDeCharge::SOCIETE.(ValeursLues::texteOuNul($this->getAttributes()['type_uuid'] ?? null) ?? (string) $this->typeDeLaSociete?->uuid);
    }

    /**
     * Le nom de son magasin, lu avec la charge par
     * ChargesDeLaSociete::liste() quand elle l'a lue ainsi ; « Toute la
     * société » pour une charge qui n'est d'aucun magasin.
     */
    public function nomDuMagasin(): string
    {
        return $this->magasin_id === null
            ? TextesDesCharges::TOUTE_LA_SOCIETE
            : ValeursLues::texteOuNul($this->getAttributes()['magasin_nom'] ?? null) ?? (string) $this->magasin?->nom;
    }

    public function nature(): NatureDeCharge
    {
        return $this->type?->nature() ?? $this->typeDeLaSociete->nature ?? NatureDeCharge::Services;
    }

    /**
     * Le jour de sa validation, dans le calendrier de Paris, celui de la
     * société dont la charge est une donnée ; null tant qu'elle n'est pas
     * validée.
     */
    public function jourDeValidation(): ?string
    {
        return $this->validee_le === null ? null : Calendrier::deParis()->jourDe($this->validee_le);
    }

    public function estValidee(): bool
    {
        return $this->etat === EtatDeLaCharge::Validee;
    }
}

<?php

namespace App\Domain\Achats\Models;

use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Database\Factories\FactureAchatFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Enregistre une facture reçue d'un fournisseur (réception de
 * marchandise) : jamais supprimée une fois validée (voir delete() et
 * FactureAchatPolicy), au même titre qu'une facture de vente émise. Sa
 * modification après validation dépend en revanche de sa source -- voir
 * estModifiable() -- une correction peut aussi toujours passer par un
 * avoir fournisseur (voir AvoirFournisseur).
 */
class FactureAchat extends Model
{
    use FigeLeNomDeSesAuteurs;

    public const REFUS_VALIDEE = 'Cette facture d\'achat est validée : elle ne se supprime plus.';

    public const REFUS_INTROUVABLE = 'Cette facture d\'achat n\'existe pas, ou plus, dans ce magasin.';

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'cree_par' => 'cree_par_nom',
        ];
    }

    /** @use HasFactory<FactureAchatFactory> */
    use BelongsToMagasin, HasFactory, VentileLaTvaParTaux;

    protected $table = 'factures_achat';

    // numero, rang_validation, statut, date_validation, totaux : jamais en
    // mass assignment, uniquement via
    // CreerFactureAchatAction/ValiderFactureAchatAction.
    protected $fillable = [
        'fournisseur_id',
        'numero_fournisseur',
        'date_facture',
        'devise',
        'notes',
        'source',
        'escompte_pourcentage',
    ];

    /**
     * date_validation est l'instant de la validation, enregistré en UTC et
     * relu à l'heure de Paris (voir HorodatageUtc) ; rang_validation, son
     * rang parmi les validations du magasin (voir
     * ValiderFactureAchatAction).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'statut' => StatutFactureAchat::class,
            'source' => SourceFactureAchat::class,
            'date_facture' => 'date',
            'date_validation' => HorodatageUtc::class.':'.config('app.timezone'),
            'rang_validation' => 'integer',
            'total_ht' => 'decimal:2',
            'total_tva' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'escompte_pourcentage' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FactureAchat $factureAchat) {
            $factureAchat->statut ??= StatutFactureAchat::Brouillon;
            $factureAchat->devise ??= 'EUR';
        });

        static::saving(function (FactureAchat $factureAchat) {
            $escompte = (float) $factureAchat->escompte_pourcentage;

            if ($escompte < 0 || $escompte > 100) {
                throw new InvalidArgumentException('L\'escompte doit être compris entre 0 et 100 %.');
            }
        });
    }

    /**
     * Le dernier rempart, derrière SupprimerFactureAchatAction et
     * FactureAchatPolicy::delete() : seul un brouillon se supprime, quel
     * que soit le chemin qui le demande. Son statut est relu en base, sous
     * un verrou tenu jusqu'à la suppression : un brouillon chargé, puis
     * validé par une autre requête avant d'être supprimé (voir
     * ValiderFactureAchatAction, qui le relit sous le même verrou), reste,
     * avec son numéro, ses lignes, sa réception en stock et ses nouveaux
     * produits. Une facture d'achat déjà supprimée ne se supprime pas une
     * seconde fois.
     *
     * @throws LogicException
     */
    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            $enBase = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($enBase === null) {
                throw new LogicException(self::REFUS_INTROUVABLE);
            }

            if ($enBase->statut !== StatutFactureAchat::Brouillon) {
                throw new LogicException(self::REFUS_VALIDEE);
            }

            return parent::delete();
        });
    }

    /**
     * Une facture d'achat garde son fournisseur, même archivé depuis (voir
     * les SoftDeletes de Fournisseur) : elle s'affiche et se retrouve
     * toujours avec lui.
     */
    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneAchat::class)->orderBy('ordre')->orderBy('id');
    }

    public function avoirsFournisseur(): HasMany
    {
        return $this->hasMany(AvoirFournisseur::class);
    }

    /**
     * Ses avoirs fournisseur émis, dans l'ordre de leur émission, que la
     * fiche de la facture d'achat suit (voir FactureAchatResource) : par
     * date d'émission, en UTC, puis par numéro, donné sous verrou à
     * l'émission, pour deux avoirs émis dans la même seconde (voir
     * EmettreAvoirFournisseurAction). La base de données ne garantit aucun
     * ordre sans tri, et un brouillon créé avant un autre peut être émis
     * après lui.
     */
    public function avoirsFournisseurEmis(): HasMany
    {
        return $this->avoirsFournisseur()
            ->where('statut', StatutAvoirFournisseur::Emise)
            ->orderBy('date_emission')
            ->orderBy('numero');
    }

    /**
     * Même logique que Facture::possedeDesLignesCreditables() côté vente :
     * un avoir fournisseur peut être partiel, cette facture peut donc en
     * recevoir plusieurs tant qu'il reste une ligne dont la quantité reçue
     * n'a pas été intégralement retournée (et encore présente en stock,
     * voir LigneAchat::scopeRetournables()).
     */
    public function possedeDesLignesRetournables(): bool
    {
        return $this->lignes()->retournables()->exists();
    }

    /**
     * Les factures d'achat validées dont il reste au moins un article à
     * retourner : celles que propose la création d'un avoir fournisseur
     * depuis le menu « Avoirs fournisseur ».
     *
     * @param  Builder<FactureAchat>  $query
     * @return Builder<FactureAchat>
     */
    public function scopeRetournables(Builder $query): Builder
    {
        return $query
            ->where('statut', StatutFactureAchat::Validee)
            ->whereHas('lignes', fn (Builder $lignes): Builder => $lignes->retournables());
    }

    /**
     * Le total TTC de la facture moins ceux des avoirs fournisseur émis qui
     * la créditent, en centimes, chaque montant enregistré lu au centime
     * exact, jamais recalculé ni additionné par la base.
     */
    public function totalTtcApresAvoirsFournisseurEnCentimes(): int
    {
        $calculateur = app(CalculateurLigne::class);

        return $calculateur->enCentiemes($this->total_ttc) - $this->avoirsFournisseurEmis()
            ->get(['total_ttc'])
            ->sum(fn (AvoirFournisseur $avoir): int => $calculateur->enCentiemes($avoir->total_ttc));
    }

    /**
     * Un produit de ses lignes archivé depuis leur saisie (voir les
     * SoftDeletes de Produit) : il ne se reçoit plus et ne se corrige plus
     * en stock tant qu'il n'est pas restauré (voir
     * ValiderFactureAchatAction et ModifierFactureAchatAction).
     */
    public function produitArchive(): ?Produit
    {
        return Produit::onlyTrashed()->whereIn('id', $this->lignes()->select('produit_id'))->orderBy('nom')->orderBy('id')->first();
    }

    /**
     * Une facture saisie manuellement reste modifiable même après
     * validation -- la personne qui l'enregistre peut se tromper, voir
     * ModifierFactureAchatAction qui recalcule alors le delta de stock et
     * le CMUP plutôt que de les réappliquer en aveugle.
     * Une facture récupérée automatiquement par API n'est en revanche
     * jamais modifiable, quel que soit son statut : une erreur ne pourrait
     * venir que de la source elle-même, jamais d'une saisie locale.
     *
     * Dès qu'un avoir fournisseur porte sur cette facture, elle se fige
     * malgré tout : chaque ligne d'avoir est liée à la ligne d'achat
     * qu'elle retourne (voir LigneAchat::quantiteDejaRetournee()), qu'une
     * modification remplacerait entièrement, et la base refuse d'effacer
     * une ligne d'achat retournée. Un brouillon d'avoir y suffit : l'écran
     * n'en laisse jamais (voir EnregistrerAvoirFournisseurAction), mais sa
     * ligne retient aussi la ligne d'achat. Toute correction supplémentaire
     * passe alors par un nouvel avoir.
     */
    public function estModifiable(): bool
    {
        if ($this->source !== SourceFactureAchat::Manuelle) {
            return false;
        }

        if ($this->statut === StatutFactureAchat::Validee) {
            return $this->avoirsFournisseur()->doesntExist();
        }

        return true;
    }
}

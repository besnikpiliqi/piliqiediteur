<?php

namespace App\Domain\Achats\Models;

use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Database\Factories\FactureAchatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Enregistre une facture reçue d'un fournisseur (réception de
 * marchandise) : jamais supprimée une fois validée (voir
 * FactureAchatPolicy), au même titre qu'une facture de vente émise. Sa
 * modification après validation dépend en revanche de sa source -- voir
 * estModifiable() -- une correction peut aussi toujours passer par un
 * avoir fournisseur (voir AvoirFournisseur).
 */
class FactureAchat extends Model
{
    use FigeLeNomDeSesAuteurs;

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

    // numero, statut, date_validation, totaux : jamais en mass assignment,
    // uniquement via CreerFactureAchatAction/ValiderFactureAchatAction.
    protected $fillable = [
        'fournisseur_id',
        'numero_fournisseur',
        'date_facture',
        'devise',
        'notes',
        'source',
        'escompte_pourcentage',
    ];

    protected $casts = [
        'statut' => StatutFactureAchat::class,
        'source' => SourceFactureAchat::class,
        'date_facture' => 'date',
        'date_validation' => 'datetime',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'escompte_pourcentage' => 'decimal:2',
    ];

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

    public function avoirsFournisseurEmis(): HasMany
    {
        return $this->avoirsFournisseur()->where('statut', StatutAvoirFournisseur::Emise);
    }

    /**
     * Même logique que Facture::possedeDesLignesCreditables() côté vente :
     * un avoir fournisseur peut être partiel, cette facture peut donc en
     * recevoir plusieurs tant qu'il reste une ligne dont la quantité reçue
     * n'a pas été intégralement retournée (et encore présente en stock,
     * voir LigneAchat::quantiteRetournableRestante()).
     */
    public function possedeDesLignesRetournables(): bool
    {
        return $this->lignes->contains(fn (LigneAchat $ligne) => $ligne->quantiteRetournableRestante() > 0);
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
     * Dès qu'un avoir fournisseur a été émis sur cette facture, elle se
     * fige malgré tout : la quantité déjà retournée (voir
     * LigneAchat::quantiteDejaRetournee()) est calculée par référence aux
     * lignes d'achat existantes, qu'une modification remplace entièrement.
     * Toute correction supplémentaire passe alors par un nouvel avoir.
     */
    public function estModifiable(): bool
    {
        if ($this->source !== SourceFactureAchat::Manuelle) {
            return false;
        }

        if ($this->statut === StatutFactureAchat::Validee) {
            return $this->avoirsFournisseurEmis()->doesntExist();
        }

        return true;
    }
}

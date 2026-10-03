<?php

namespace App\Domain\Achats\Models;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Database\Factories\FactureAchatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Enregistre une facture reçue d'un fournisseur (réception de
 * marchandise) : jamais modifiée ni supprimée une fois validée (voir
 * FactureAchatPolicy), au même titre qu'une facture de vente émise. Une
 * correction passe par un avoir fournisseur (voir AvoirFournisseur),
 * jamais par une réécriture de cette facture.
 */
class FactureAchat extends Model
{
    /** @use HasFactory<FactureAchatFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'factures_achat';

    // numero, statut, date_validation, totaux : jamais en mass assignment,
    // uniquement via CreerFactureAchatAction/ValiderFactureAchatAction.
    protected $fillable = [
        'fournisseur_id',
        'numero_fournisseur',
        'date_facture',
        'devise',
        'notes',
    ];

    protected $casts = [
        'statut' => StatutFactureAchat::class,
        'date_facture' => 'date',
        'date_validation' => 'datetime',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (FactureAchat $factureAchat) {
            $factureAchat->statut ??= StatutFactureAchat::Brouillon;
            $factureAchat->devise ??= 'EUR';
            $factureAchat->cree_par ??= auth()->id();
        });
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneAchat::class)->orderBy('ordre');
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
}

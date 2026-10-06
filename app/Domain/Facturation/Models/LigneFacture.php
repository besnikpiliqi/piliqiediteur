<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LigneFacture extends Model
{
    use BelongsToMagasin;

    protected $table = 'lignes_facture';

    protected $fillable = [
        'facture_id',
        'produit_id',
        'description',
        'reference',
        'quantite',
        'prix_unitaire_ht',
        'prix_unitaire_ttc',
        'prix_reference',
        'taux_tva',
        'remise_pourcentage',
        'montant_ht',
        'montant_tva',
        'montant_ttc',
        'ordre',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'prix_unitaire_ttc' => 'decimal:2',
        'prix_reference' => PrixReference::class,
        'taux_tva' => 'decimal:2',
        'remise_pourcentage' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function lignesAvoir(): HasMany
    {
        return $this->hasMany(LigneAvoir::class);
    }

    /**
     * Quantité déjà créditée par des avoirs ÉMIS référençant cette ligne.
     * Un avoir encore brouillon n'a aucune existence légale (voir
     * Avoir::class) : il ne doit jamais réduire la quantité restante
     * créditable, au même titre qu'il ne bloque pas un nouvel avoir (voir
     * CreerAvoirAction).
     */
    public function quantiteDejaCreditee(): int
    {
        return (int) $this->lignesAvoir()
            ->whereHas('avoir', fn ($query) => $query->where('statut', StatutAvoir::Emise))
            ->sum('quantite');
    }

    /**
     * Permet un avoir partiel, et plusieurs avoirs successifs sur une même
     * ligne, tant que leur somme ne dépasse jamais la quantité facturée
     * (voir CreerAvoirAction).
     */
    public function quantiteCreditableRestante(): int
    {
        return $this->quantite - $this->quantiteDejaCreditee();
    }
}

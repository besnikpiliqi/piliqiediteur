<?php

namespace App\Domain\Achats\Models;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Casts\QuantiteArticles;
use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneAvoirFournisseur extends Model
{
    use BelongsToMagasin;

    protected $table = 'lignes_avoir_fournisseur';

    protected $fillable = [
        'avoir_fournisseur_id',
        'ligne_achat_id',
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
        'quantite' => QuantiteArticles::class,
        'prix_unitaire_ht' => 'decimal:2',
        'prix_unitaire_ttc' => 'decimal:2',
        'prix_reference' => PrixReference::class,
        'taux_tva' => 'decimal:2',
        'remise_pourcentage' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    public function avoirFournisseur(): BelongsTo
    {
        return $this->belongsTo(AvoirFournisseur::class);
    }

    public function ligneAchat(): BelongsTo
    {
        return $this->belongsTo(LigneAchat::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}

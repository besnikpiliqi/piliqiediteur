<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'taux_tva',
        'remise_pourcentage',
        'montant_ht',
        'montant_tva',
        'montant_ttc',
        'ordre',
    ];

    protected $casts = [
        'quantite' => 'decimal:3',
        'prix_unitaire_ht' => 'decimal:2',
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
}

<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne figée au moment de sa création : description, prix, taux de TVA
 * sont un SNAPSHOT. Modifier le produit catalogue ensuite ne doit jamais
 * changer une ligne de devis déjà créée.
 */
class LigneDevis extends Model
{
    use BelongsToMagasin;

    protected $table = 'lignes_devis';

    protected $fillable = [
        'devis_id',
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
        'quantite' => 'decimal:3',
        'prix_unitaire_ht' => 'decimal:2',
        'prix_unitaire_ttc' => 'decimal:2',
        'prix_reference' => PrixReference::class,
        'taux_tva' => 'decimal:2',
        'remise_pourcentage' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}

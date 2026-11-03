<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La ligne d'un avoir : ses montants s'arrêtent à l'émission (voir
 * EmettreAvoirAction), puis elle est figée (voir
 * ProtegeLesDonneesValidees).
 */
class LigneAvoir extends Model
{
    use BelongsToMagasin, ProtegeLesDonneesValidees;

    protected $table = 'lignes_avoir';

    protected $fillable = [
        'avoir_id',
        'ligne_facture_id',
        'produit_id',
        'nature',
        'description',
        'reference',
        'quantite',
        'prix_unitaire_ht',
        'prix_unitaire_ttc',
        'taux_tva',
        'taux_tva_du_prix',
        'remise_pourcentage',
        'montant_ht',
        'montant_tva',
        'montant_ttc',
        'ordre',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire_ht' => 'decimal:6',
        'prix_unitaire_ttc' => 'decimal:2',
        'nature' => NatureLigne::class,
        'taux_tva' => 'decimal:2',
        'taux_tva_du_prix' => 'decimal:2',
        'remise_pourcentage' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    /**
     * Son avoir, avant comme après la modification, est numéroté en base :
     * la ligne appartient à un document émis.
     */
    public function estValidee(): bool
    {
        return $this->documentNumeroteEnBase(Avoir::class, [$this->getRawOriginal('avoir_id'), $this->getAttribute('avoir_id')]);
    }

    protected function regleDInalterabilite(): string
    {
        return 'Les lignes d\'un avoir émis ne se modifient plus.';
    }

    public function avoir(): BelongsTo
    {
        return $this->belongsTo(Avoir::class);
    }

    public function ligneFacture(): BelongsTo
    {
        return $this->belongsTo(LigneFacture::class);
    }

    /**
     * Une ligne garde son produit, même archivé depuis (voir les
     * SoftDeletes de Produit) : le retour le remet quand même en stock
     * (voir RestaurerStockApresEmissionAvoir).
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }
}

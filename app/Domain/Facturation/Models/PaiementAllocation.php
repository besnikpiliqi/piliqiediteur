<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'un paiement règle d'une facture : signé avec son paiement, jamais
 * modifié ni supprimé (voir ProtegeLesDonneesValidees et les déclencheurs
 * de la table).
 */
class PaiementAllocation extends Model
{
    use BelongsToMagasin, ProtegeLesDonneesValidees;

    protected $table = 'paiement_allocations';

    protected $fillable = [
        'paiement_id',
        'facture_id',
        'montant_alloue',
    ];

    protected $casts = [
        'montant_alloue' => 'decimal:2',
    ];

    public function estValidee(): bool
    {
        return $this->exists;
    }

    protected function regleDInalterabilite(): string
    {
        return 'Ce qu\'un paiement règle d\'une facture ne se modifie ni ne se supprime.';
    }

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}

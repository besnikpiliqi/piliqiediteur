<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'un avoir crédite d'une facture : né pendant l'émission de l'un ou
 * de l'autre et signé avec lui, jamais modifié ni supprimé (voir
 * ProtegeLesDonneesValidees et les déclencheurs de la table).
 */
class AvoirAllocation extends Model
{
    use BelongsToMagasin, ProtegeLesDonneesValidees;

    protected $table = 'avoir_allocations';

    protected $fillable = [
        'avoir_id',
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
        return 'Ce qu\'un avoir crédite d\'une facture ne se modifie ni ne se supprime.';
    }

    public function avoir(): BelongsTo
    {
        return $this->belongsTo(Avoir::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}

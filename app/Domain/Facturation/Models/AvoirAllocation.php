<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'un avoir crédite d'une facture : né pendant l'émission de l'un ou
 * de l'autre et signé avec lui, ou pendant l'enregistrement d'un paiement
 * (paiement_id) et signé avec ce paiement ; jamais modifié ni supprimé
 * (voir ProtegeLesDonneesValidees et les déclencheurs de la table).
 */
class AvoirAllocation extends Model
{
    use BelongsToMagasin, ProtegeLesDonneesValidees;

    protected $table = 'avoir_allocations';

    protected $fillable = [
        'avoir_id',
        'facture_id',
        'paiement_id',
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

    /**
     * Le paiement qui a imputé ce crédit, null pour un crédit imputé à
     * l'émission d'un avoir ou d'une facture.
     */
    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }
}

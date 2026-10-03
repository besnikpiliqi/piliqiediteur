<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\PaiementAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaiementAllocation extends Model
{
    /** @use HasFactory<PaiementAllocationFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'paiement_allocations';

    protected $fillable = [
        'paiement_id',
        'facture_id',
        'montant_alloue',
    ];

    protected $casts = [
        'montant_alloue' => 'decimal:2',
    ];

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}

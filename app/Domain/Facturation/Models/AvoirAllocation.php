<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\AvoirAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvoirAllocation extends Model
{
    /** @use HasFactory<AvoirAllocationFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'avoir_allocations';

    protected $fillable = [
        'avoir_id',
        'facture_id',
        'montant_alloue',
    ];

    protected $casts = [
        'montant_alloue' => 'decimal:2',
    ];

    public function avoir(): BelongsTo
    {
        return $this->belongsTo(Avoir::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}

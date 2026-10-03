<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Integrations\Pdp\Models\TransmissionPdp;
use App\Models\User;
use Database\Factories\ClotureCaisseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Agrège les ventes à des clients particuliers d'une journée pour
 * l'e-reporting (voir CloturerCaisseAction) -- jamais les ventes à des
 * clients professionnels, qui relèvent de l'e-invoicing individuelle
 * transmise facture par facture (voir Facture::relevantDeLeInvoicing).
 *
 * PAS de SoftDeletes ici, volontairement : document fiscal au même titre
 * qu'une facture, la loi impose sa conservation.
 */
class ClotureCaisse extends Model
{
    /** @use HasFactory<ClotureCaisseFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'clotures_caisse';

    // numero, totaux, cloturee_a : jamais en mass assignment, uniquement
    // via CloturerCaisseAction.
    protected $fillable = [
        'date_cloture',
    ];

    protected $casts = [
        'date_cloture' => 'date',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'cloturee_a' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ClotureCaisse $cloture) {
            $cloture->cree_par ??= auth()->id();
        });
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    public function transmissionsPdp(): MorphMany
    {
        return $this->morphMany(TransmissionPdp::class, 'transmissible');
    }
}

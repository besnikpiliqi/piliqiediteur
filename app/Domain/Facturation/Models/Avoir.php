<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Database\Factories\AvoirFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PAS de SoftDeletes ici, volontairement : au même titre qu'une facture,
 * un avoir est un document fiscal dont la loi impose la conservation
 * (voir AvoirPolicy et la migration create_avoirs_table).
 */
class Avoir extends Model
{
    /** @use HasFactory<AvoirFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'avoirs';

    // numero, statut, date_emission, totaux : jamais en mass assignment,
    // uniquement via CreerAvoirAction/EmettreAvoirAction.
    protected $fillable = [
        'facture_id',
        'motif',
        'devise',
    ];

    protected $casts = [
        'statut' => StatutAvoir::class,
        'date_emission' => 'datetime',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Avoir $avoir) {
            $avoir->statut ??= StatutAvoir::Brouillon;
            $avoir->devise ??= 'EUR';
            $avoir->cree_par ??= auth()->id();
        });
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneAvoir::class)->orderBy('ordre');
    }
}

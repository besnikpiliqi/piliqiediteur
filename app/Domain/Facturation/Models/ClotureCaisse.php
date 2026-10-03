<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Database\Factories\ClotureCaisseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * Cette clôture n'est plus jamais transmise au PDP individuellement :
     * voir le docblock de SoumissionEReporting, qui agrège plusieurs
     * clôtures sur toute une période d'e-reporting avant transmission.
     */
    public function soumissionEReporting(): BelongsTo
    {
        // Clé étrangère explicite : la convention par défaut de Laravel
        // devinerait "soumission_e_reporting_id" (elle coupe aussi entre
        // deux majuscules consécutives), qui ne correspond pas à la
        // colonne réelle "soumission_ereporting_id" de la migration.
        return $this->belongsTo(SoumissionEReporting::class, 'soumission_ereporting_id');
    }
}

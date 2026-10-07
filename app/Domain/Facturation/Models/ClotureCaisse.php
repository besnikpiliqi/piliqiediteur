<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Database\Factories\ClotureCaisseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;

/**
 * Agrège les ventes à des clients particuliers d'une journée, moins les
 * avoirs qui leur ont été émis ce jour-là, pour l'e-reporting (voir
 * CloturerCaisseAction) -- jamais les ventes à des clients
 * professionnels, qui relèvent de l'e-invoicing individuelle transmise
 * facture par facture (voir Facture::relevantDeLeInvoicing).
 *
 * PAS de SoftDeletes ici, volontairement : document fiscal au même titre
 * qu'une facture, la loi impose sa conservation.
 */
class ClotureCaisse extends Model
{
    /** @use HasFactory<ClotureCaisseFactory> */
    use BelongsToMagasin, HasFactory, VentileLaTvaParTaux;

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
     * Les avoirs à des particuliers émis ce jour-là, déduits des ventes
     * (voir CloturerCaisseAction).
     */
    public function avoirs(): HasMany
    {
        return $this->hasMany(Avoir::class);
    }

    /**
     * Les lignes de toutes les ventes de la journée, pour la ventilation
     * par taux de TVA (voir VentileLaTvaParTaux).
     */
    public function lignes(): HasManyThrough
    {
        return $this->hasManyThrough(LigneFacture::class, Facture::class);
    }

    public function lignesAvoir(): HasManyThrough
    {
        return $this->hasManyThrough(LigneAvoir::class, Avoir::class);
    }

    /**
     * @return Collection<int, array{nature: ?NatureLigne, taux_tva: mixed, montant_ht: mixed, montant_tva: mixed, montant_ttc: mixed}>
     */
    protected function lignesAVentiler(): Collection
    {
        return self::montantsDesLignes($this->lignes)->concat(self::montantsDeduits($this->lignesAvoir));
    }

    /**
     * Une clôture n'est jamais transmise au PDP individuellement :
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

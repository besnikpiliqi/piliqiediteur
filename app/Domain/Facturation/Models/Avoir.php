<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Database\Factories\AvoirFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Rattaché à un CLIENT, jamais à une seule facture : un avoir peut
 * regrouper des lignes provenant de plusieurs factures différentes du
 * même client (voir le docblock de CreerAvoirAction), chaque ligne restant
 * individuellement traçable jusqu'à sa facture d'origine via
 * LigneAvoir::ligne_facture_id.
 *
 * PAS de SoftDeletes ici, volontairement : au même titre qu'une facture,
 * un avoir est un document fiscal dont la loi impose la conservation
 * (voir AvoirPolicy et la migration create_avoirs_table).
 */
class Avoir extends Model
{
    /** @use HasFactory<AvoirFactory> */
    use BelongsToMagasin, HasFactory, VentileLaTvaParTaux;

    protected $table = 'avoirs';

    // numero, statut, date_emission, totaux : jamais en mass assignment,
    // uniquement via CreerAvoirAction/EmettreAvoirAction.
    protected $fillable = [
        'client_id',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneAvoir::class)->orderBy('ordre');
    }

    /**
     * Les factures distinctes dont proviennent les lignes de cet avoir
     * (potentiellement plusieurs, voir le docblock de classe). Charger
     * lignes.ligneFacture.facture au préalable pour éviter un N+1.
     *
     * @return Collection<int, Facture>
     */
    public function facturesConcernees(): Collection
    {
        return $this->lignes
            ->map(fn (LigneAvoir $ligne) => $ligne->ligneFacture?->facture)
            ->filter()
            ->unique('id')
            ->values();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AvoirAllocation::class);
    }

    public function montantAlloue(): float
    {
        return round((float) $this->allocations()->sum('montant_alloue'), 2);
    }

    /**
     * Solde du crédit encore disponible pour ce client : un avoir émis
     * n'est jamais remboursé en espèces/virement par ce logiciel (pas
     * d'intégration bancaire) -- il s'applique automatiquement à la
     * prochaine facture du même client (voir AppliquerAvoirsDisponiblesAction),
     * en tout ou en partie, jusqu'à épuisement.
     */
    public function montantNonAlloue(): float
    {
        if ($this->statut !== StatutAvoir::Emise) {
            return 0.0;
        }

        return round((float) $this->total_ttc - $this->montantAlloue(), 2);
    }
}

<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\AvoirFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Les avoirs à des particuliers émis tel jour qu'aucune clôture n'a
     * encore déduits de ses ventes (voir CloturerCaisseAction) : un retour
     * se compte le jour où il a lieu, pas celui de la vente d'origine.
     */
    public function scopeEnAttenteDeCloture(Builder $query, CarbonInterface|string $jour): Builder
    {
        return $query
            ->whereHas('client', fn (Builder $client) => $client->where('type_client', TypeClient::Particulier))
            ->whereDate('date_emission', $jour)
            ->where('statut', StatutAvoir::Emise)
            ->whereNull('cloture_caisse_id');
    }

    public function clotureCaisse(): BelongsTo
    {
        return $this->belongsTo(ClotureCaisse::class);
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
        return app(CalculateurLigne::class)->sommer($this->allocations()->pluck('montant_alloue'));
    }

    /**
     * Solde du crédit encore disponible pour ce client : un avoir émis
     * n'est jamais remboursé en espèces/virement par ce logiciel (pas
     * d'intégration bancaire). Il réduit d'abord le reste dû des factures
     * qu'il corrige (voir ImputerAvoirSurSesFacturesAction) ; l'excédent
     * s'applique automatiquement aux prochaines factures du même client
     * (voir AppliquerAvoirsDisponiblesAction), jusqu'à épuisement.
     */
    public function montantNonAlloue(): float
    {
        if ($this->statut !== StatutAvoir::Emise) {
            return 0.0;
        }

        return app(CalculateurLigne::class)->soustraire($this->total_ttc, $this->montantAlloue());
    }
}

<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Integrations\Pdp\Models\TransmissionPdp;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\FactureFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * PAS de SoftDeletes ici, volontairement : une facture ne se supprime
 * jamais, même "doucement" (voir FacturePolicy::delete() et la migration
 * create_factures_table). La loi impose sa conservation ; une correction
 * passe par un avoir, jamais par une suppression.
 */
class Facture extends Model
{
    /** @use HasFactory<FactureFactory> */
    use BelongsToMagasin, HasFactory, VentileLaTvaParTaux;

    protected $table = 'factures';

    // numero, statut, date_emission, totaux, montant_paye : jamais en
    // mass assignment, uniquement via les Actions dédiées (voir
    // app/Domain/Facturation/Actions).
    protected $fillable = [
        'client_id',
        'devis_id',
        'date_echeance',
        'devise',
        'conditions_paiement',
        'notes',
    ];

    protected $casts = [
        'statut' => StatutFacture::class,
        'date_emission' => 'datetime',
        'date_echeance' => 'date',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Facture $facture) {
            $facture->statut ??= StatutFacture::Brouillon;
            $facture->devise ??= 'EUR';
            // Jamais fourni par le payload : c'est toujours l'auteur réel
            // de la requête, pour que la restriction de visibilité par
            // vendeur (voir FacturePolicy) ne puisse pas être usurpée.
            $facture->cree_par ??= auth()->id();
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

    /**
     * Fermetures de caisse personnelles qui ont compté cette vente (une
     * seule au plus, voir ClotureCaissePersonnelle::factures()).
     */
    public function cloturesCaissePersonnelles(): BelongsToMany
    {
        return $this->belongsToMany(ClotureCaissePersonnelle::class, 'clotures_caisse_personnelles_factures');
    }

    /**
     * Les ventes d'un vendeur pas encore comptées dans une fermeture de sa
     * caisse : émises (ni brouillon, ni annulée) et jamais notées par une
     * fermeture précédente -- quel que soit le moment où le brouillon a été
     * créé, et donc son ID.
     */
    public function scopeNonFermeesParLeVendeur(Builder $query, int $vendeurId): Builder
    {
        return $query
            ->where('cree_par', $vendeurId)
            ->whereNotIn('statut', [StatutFacture::Brouillon, StatutFacture::Annulee])
            ->whereDoesntHave('cloturesCaissePersonnelles');
    }

    /**
     * Les ventes à des particuliers émises tel jour qu'aucune clôture n'a
     * encore comptées (voir CloturerCaisseAction) -- jamais un brouillon ni
     * une facture annulée, jamais une vente à un professionnel, qui relève
     * de la facture électronique (voir relevantDeLeInvoicing()).
     */
    public function scopeEnAttenteDeCloture(Builder $query, CarbonInterface|string $jour): Builder
    {
        return $query
            ->whereHas('client', fn (Builder $client) => $client->where('type_client', TypeClient::Particulier))
            ->whereDate('date_emission', $jour)
            ->whereNotIn('statut', [StatutFacture::Brouillon, StatutFacture::Annulee])
            ->whereNull('cloture_caisse_id');
    }

    public function devisOrigine(): BelongsTo
    {
        return $this->belongsTo(Devis::class, 'devis_id');
    }

    public function clotureCaisse(): BelongsTo
    {
        return $this->belongsTo(ClotureCaisse::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneFacture::class)->orderBy('ordre');
    }

    public function transmissionsPdp(): MorphMany
    {
        return $this->morphMany(TransmissionPdp::class, 'transmissible');
    }

    public function allocationsPaiement(): HasMany
    {
        return $this->hasMany(PaiementAllocation::class);
    }

    public function allocationsAvoir(): HasMany
    {
        return $this->hasMany(AvoirAllocation::class);
    }

    /**
     * Un avoir n'appartient pas à une seule facture (voir le docblock de
     * CreerAvoirAction) : il référence cette facture indirectement, via
     * chacune de ses lignes (LigneAvoir::ligne_facture_id). Impossible
     * d'utiliser une relation Eloquent classique ici, d'où une requête
     * explicite plutôt qu'un hasMany.
     */
    public function avoirsConcernes(): Builder
    {
        return Avoir::query()->whereHas(
            'lignes.ligneFacture',
            fn (Builder $query) => $query->where('facture_id', $this->getKey()),
        );
    }

    /**
     * Un avoir peut être partiel (voir CreerAvoirAction) : une facture peut
     * donc faire l'objet de plusieurs avoirs successifs au fil du temps,
     * tant qu'il reste au moins une ligne dont la quantité facturée n'a
     * pas été intégralement créditée.
     */
    public function avoirsEmisConcernes(): Builder
    {
        return $this->avoirsConcernes()->where('statut', StatutAvoir::Emise);
    }

    public function possedeDesLignesCreditables(): bool
    {
        return $this->lignes->contains(fn (LigneFacture $ligne) => $ligne->quantiteCreditableRestante() > 0);
    }

    public function montantPaye(): float
    {
        return app(CalculateurLigne::class)->sommer($this->allocationsPaiement()->pluck('montant_alloue'));
    }

    /**
     * Crédit appliqué sur cette facture par un ou plusieurs avoirs
     * disponibles du client (voir AppliquerAvoirsDisponiblesAction) --
     * jamais un paiement réellement encaissé, mais vient réduire le solde
     * dû exactement de la même façon.
     */
    public function montantCrediteParAvoir(): float
    {
        return app(CalculateurLigne::class)->sommer($this->allocationsAvoir()->pluck('montant_alloue'));
    }

    public function montantRestant(): float
    {
        return app(CalculateurLigne::class)->soustraire($this->total_ttc, $this->montantPaye(), $this->montantCrediteParAvoir());
    }

    /**
     * Après un paiement ou l'imputation d'un avoir : « payée » quand plus
     * rien n'est dû, « partiellement payée » sinon.
     *
     * @throws LogicException
     */
    public function constaterUnReglement(): void
    {
        $nouveauStatut = $this->montantRestant() <= 0.0
            ? StatutFacture::Payee
            : StatutFacture::PartiellementPayee;

        if ($this->statut === $nouveauStatut) {
            return;
        }

        if (! $this->statut->peutTransitionnerVers($nouveauStatut)) {
            throw new LogicException("Transition de statut invalide sur la facture {$this->numero} : [{$this->statut->value}] -> [{$nouveauStatut->value}].");
        }

        $this->forceFill(['statut' => $nouveauStatut])->save();
    }

    /**
     * Détermine le circuit réglementaire applicable : une facture à un
     * client professionnel relève de l'e-invoicing (transmission PDP
     * individuelle) ; un particulier relève de l'e-reporting agrégé via
     * la clôture de caisse journalière (non transmise facture par facture).
     */
    public function relevantDeLeInvoicing(): bool
    {
        return $this->client->estProfessionnel();
    }
}

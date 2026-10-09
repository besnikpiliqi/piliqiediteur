<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Concerns\EstEnvoyeParEmail;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Stock\Models\MouvementStock;
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
use Illuminate\Support\Carbon;
use LogicException;

/**
 * PAS de SoftDeletes ici, volontairement : une facture numérotée (émise ou
 * annulée) ne se supprime jamais, même "doucement" -- la loi impose sa
 * conservation, et une correction passe par un avoir. Un brouillon, sans
 * numéro ni existence légale, peut en revanche être supprimé
 * définitivement (voir FacturePolicy::delete()).
 */
class Facture extends Model implements DocumentEnvoyable
{
    /** @use HasFactory<FactureFactory> */
    use BelongsToMagasin, EstEnvoyeParEmail, HasFactory, VentileLaTvaParTaux;

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

    /**
     * Un document légal garde son client, même archivé depuis (voir les
     * SoftDeletes de Client) : la facture reste due, se réimprime et se
     * relance, et la clôture du jour la compte.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
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

    /**
     * Ce que cette facture a fait sortir du stock pour ce produit à son
     * émission (voir DecrementerStockApresEmissionFacture) et qui n'y est
     * pas encore revenu, par son annulation ou par un avoir sur l'une de
     * ses lignes (voir RestaurerStockApresEmissionAvoir) : jamais plus ne
     * peut y revenir. Un article vendu sans suivi du stock n'en est jamais
     * sorti.
     */
    public function quantiteSortieDuStock(Produit $produit): int
    {
        $mouvements = MouvementStock::query()
            ->where('produit_id', $produit->getKey())
            ->where(fn (Builder $mouvements) => $mouvements
                ->whereMorphedTo('source', $this)
                ->orWhereHasMorph('source', [LigneAvoir::class], fn (Builder $ligneAvoir) => $ligneAvoir
                    ->whereHas('ligneFacture', fn (Builder $ligneFacture) => $ligneFacture->where('facture_id', $this->getKey()))))
            ->get(['type', 'quantite']);

        return -$mouvements->sum(fn (MouvementStock $mouvement): int => $mouvement->type->variation($mouvement->quantite));
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
     * rien n'est dû, « partiellement payée » sinon -- sauf pour une facture
     * en retard, qui le reste tant qu'elle n'est pas soldée : son échéance
     * est toujours dépassée (voir MarquerFacturesEnRetard).
     *
     * @throws LogicException
     */
    public function constaterUnReglement(): void
    {
        $nouveauStatut = match (true) {
            $this->montantRestant() <= 0.0 => StatutFacture::Payee,
            $this->statut === StatutFacture::EnRetard => StatutFacture::EnRetard,
            default => StatutFacture::PartiellementPayee,
        };

        if ($this->statut === $nouveauStatut) {
            return;
        }

        if (! $this->statut->peutTransitionnerVers($nouveauStatut)) {
            throw new LogicException("Transition de statut invalide sur la facture {$this->numero} : [{$this->statut->value}] -> [{$nouveauStatut->value}].");
        }

        $this->forceFill(['statut' => $nouveauStatut])->save();
    }

    /**
     * Une facture se relance (voir EnvoyerDocumentAction::relancer()) une
     * fois son échéance passée, tant qu'il reste quelque chose à régler --
     * jamais une facture payée, annulée ou soldée par un avoir.
     */
    public function estEchueEtImpayee(): bool
    {
        return $this->statut->attendUnReglement()
            && $this->date_echeance !== null
            && $this->date_echeance->lt(today())
            && $this->montantRestant() > 0;
    }

    /**
     * Les relances de cette facture parties ou en partance : une relance
     * en échec n'a jamais atteint le client, elle ne compte pas et repart
     * à la relance suivante de la facture (voir
     * EnvoyerDocumentAction::relancer()).
     */
    public function relancesComptees(): MorphMany
    {
        return $this->envois()
            ->where('nature', NatureEnvoi::Relance)
            ->where('statut', '!=', StatutEnvoi::Echec);
    }

    /**
     * Le niveau de la dernière relance de cette facture, 0 tant qu'elle n'a
     * jamais été relancée.
     */
    public function niveauDeRelanceAtteint(): int
    {
        return (int) $this->relancesComptees()->max('niveau_relance');
    }

    /**
     * Le nombre de jours depuis sa dernière relance (0 si elle a été
     * relancée aujourd'hui), null tant qu'elle n'a jamais été relancée.
     */
    public function joursDepuisLaDerniereRelance(): ?int
    {
        $derniere = $this->relancesComptees()->max('created_at');

        return $derniere === null ? null : (int) Carbon::parse($derniere)->startOfDay()->diffInDays(today());
    }

    /**
     * Le rappel d'une relance (voir EnvoyerDocumentAction::relancer()),
     * calculé au moment où elle part (voir EnvoyerDocumentJob) : ce qu'il
     * reste à payer tient compte des règlements reçus entre-temps.
     */
    public function messageDeRelance(): string
    {
        $euros = fn (float $montant): string => number_format($montant, 2, ',', ' ').' €';
        [$document, $accord] = $this->client->estProfessionnel() ? ['la facture', 'e'] : ['le ticket de caisse', ''];

        return "Sauf erreur de notre part, {$document} {$this->numero} d'un montant de {$euros((float) $this->total_ttc)} TTC, "
            ."arrivé{$accord} à échéance le {$this->date_echeance->format('d/m/Y')}, n'est pas entièrement réglé{$accord} : "
            ."il reste {$euros($this->montantRestant())} à payer.\n"
            .'Nous vous remercions de bien vouloir procéder à son règlement dans les meilleurs délais. '
            ."Si vous l'avez réglé{$accord} entre-temps, merci de ne pas tenir compte de ce message.";
    }

    /**
     * Un particulier relève de l'e-reporting agrégé (clôture de caisse),
     * pas d'une facture transmise individuellement (voir
     * relevantDeLeInvoicing()) : son document porte le libellé usuel
     * « Ticket de caisse », même s'il s'agit du même enregistrement.
     */
    public function libelleDocument(): string
    {
        return $this->client->estProfessionnel() ? 'Facture' : 'Ticket de caisse';
    }

    /**
     * Seule une facture émise existe pour son client : un brouillon n'a
     * pas encore de numéro, une facture annulée ne vaut plus rien.
     */
    public function peutEtreEnvoye(): bool
    {
        return ! in_array($this->statut, [StatutFacture::Brouillon, StatutFacture::Annulee], true);
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

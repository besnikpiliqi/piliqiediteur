<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\HorodateSonEmission;
use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Concerns\EstEnvoyeParEmail;
use App\Domain\Facturation\Concerns\FigeLeClient;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
 * (voir AvoirPolicy et la migration create_avoirs_table). Une fois
 * numéroté, il ne se modifie plus du tout (voir
 * ProtegeLesDonneesValidees) : il est signé tel qu'émis au registre fiscal
 * (voir EmettreAvoirAction), avec la copie de son client prise ce jour-là
 * et le type de client des documents qu'il corrige (voir FigeLeClient).
 */
class Avoir extends Model implements DocumentEnvoyable
{
    use BelongsToMagasin, EstEnvoyeParEmail, FigeLeClient, HorodateSonEmission, ProtegeLesDonneesValidees, VentileLaTvaParTaux;

    protected $table = 'avoirs';

    // numero, statut, date_emission, totaux, type et copie du client :
    // jamais en mass assignment, uniquement via EnregistrerAvoirAction
    // (CreerAvoirAction puis EmettreAvoirAction).
    protected $fillable = [
        'client_id',
        'motif',
        'devise',
    ];

    protected $casts = [
        'statut' => StatutAvoir::class,
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

    /**
     * Numéroté en base, donc émis.
     */
    public function estValidee(): bool
    {
        return $this->exists && $this->documentNumeroteEnBase(self::class, [$this->getKey()]);
    }

    protected function regleDInalterabilite(): string
    {
        return 'Un avoir émis ne se modifie plus.';
    }

    /**
     * Un document légal garde son client, même archivé depuis (voir les
     * SoftDeletes de Client) : il se renvoie, et il imprime la copie de ce
     * client prise à son émission (voir copieClient()).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * Les avoirs sur des tickets de caisse (voir corrigeDesTickets()), émis
     * tel jour, qu'aucune clôture n'a encore déduits de ses ventes (voir
     * CloturerCaisseAction) : un retour se compte le jour où il a lieu, pas
     * celui de la vente d'origine.
     */
    public function scopeEnAttenteDeCloture(Builder $query, CarbonInterface|string $jour): Builder
    {
        return $query
            ->where('type_client', TypeClient::Particulier)
            ->emisLe($jour)
            ->where('statut', StatutAvoir::Emise)
            ->whereNull('cloture_caisse_id');
    }

    /**
     * Un avoir sur des tickets de caisse : la clôture du jour de son
     * émission le déduit (voir scopeEnAttenteDeCloture()), la caisse ne
     * l'émet plus une fois cette journée clôturée (voir
     * EmettreAvoirAction), et le registre fiscal l'inscrit en annulation de
     * ticket. Un avoir ne mélange jamais tickets et factures (voir
     * CreerAvoirAction) : il prend le type de client des documents qu'il
     * corrige, figé à leur émission, particulier pour des tickets, même si
     * la fiche du client change depuis. Un avoir sur une facture à un
     * professionnel n'entre donc jamais dans une clôture de caisse.
     */
    public function corrigeDesTickets(): bool
    {
        return ! $this->estPourUnProfessionnel();
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

    public function libelleDocument(): string
    {
        return 'Avoir';
    }

    /**
     * Seul un avoir émis existe pour son client, comme une facture.
     */
    public function peutEtreEnvoye(): bool
    {
        return $this->statut === StatutAvoir::Emise;
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

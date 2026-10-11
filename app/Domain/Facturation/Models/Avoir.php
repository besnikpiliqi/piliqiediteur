<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\HorodateSonEmission;
use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Concerns\EstEnvoyeParEmail;
use App\Domain\Facturation\Concerns\FigeLeClient;
use App\Domain\Facturation\Concerns\FigeSonEmetteur;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\CopieClient;
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
 * (voir EmettreAvoirAction), avec le client des documents qu'il corrige,
 * son type, son nom, son adresse et ses identifiants, tels que ces
 * documents les ont figés (voir FigeLeClient), et l'émetteur qu'il
 * imprime pour toujours (voir FigeSonEmetteur).
 */
class Avoir extends Model implements DocumentEnvoyable
{
    use BelongsToMagasin, EstEnvoyeParEmail, FigeLeClient, FigeSonEmetteur, HorodateSonEmission, ProtegeLesDonneesValidees, VentileLaTvaParTaux;

    /**
     * Le refus d'un numéro saisi à la caisse qui ne désigne aucun bon
     * d'avoir encore utilisable (voir scopeBonDAvoir()).
     */
    public const BON_D_AVOIR_INTROUVABLE = 'Ce bon d\'avoir est introuvable ou déjà utilisé.';

    protected $table = 'avoirs';

    // numero, statut, date_emission, totaux, type et copie du client,
    // franchise de TVA, nature d'opération : jamais en mass assignment,
    // uniquement via EnregistrerAvoirAction (CreerAvoirAction puis
    // EmettreAvoirAction).
    protected $fillable = [
        'client_id',
        'motif',
        'devise',
    ];

    /**
     * La vente en France tant qu'aucune nature n'est reprise, comme en
     * base.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'nature_operation' => 'vente_france',
    ];

    protected $casts = [
        'statut' => StatutAvoir::class,
        'franchise_en_base' => 'boolean',
        'nature_operation' => NatureOperation::class,
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
     * SoftDeletes de Client) : il se renvoie, et son crédit s'impute sur
     * les factures de ce client. Il imprime la copie du client prise à son
     * émission (voir copieClient()).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * Le client tel que l'a figé le document qu'il corrige, et non la fiche
     * du client le jour de l'avoir : il corrige des documents d'un même
     * client, tous du même type (voir CreerAvoirAction), dont il prend déjà
     * le type, et s'adresse à celui à qui ils ont été émis. S'il en corrige
     * plusieurs, il prend la copie du plus récent.
     */
    protected function clientAFiger(): CopieClient
    {
        return $this->facturesConcernees()
            ->sortBy([['date_emission', 'desc'], ['id', 'desc']])
            ->firstOrFail()
            ->copieClient();
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
     * L'avoir émis au client comptoir du magasin actif sous ce numéro, tel
     * que le vendeur le saisit à la caisse pour utiliser son bon d'avoir
     * (voir EnregistrerVenteCaisseAction), sans tenir compte de la casse
     * ni des espaces autour. Un avoir d'un client nommé ou d'un autre
     * magasin (MagasinScope) n'en est jamais un ; son crédit restant (voir
     * montantNonAlloue()) dit s'il sert encore.
     */
    public function scopeBonDAvoir(Builder $query, string $numero): Builder
    {
        return $query
            ->where('statut', StatutAvoir::Emise)
            ->where('numero', mb_strtoupper(trim($numero)))
            ->whereIn('client_id', Client::query()->where('est_comptoir', true)->select('id'));
    }

    /**
     * La mention du bon d'avoir que l'avoir a signée à son émission (voir
     * MentionsFiscales::bonDAvoir()) et que son PDF imprime, avec le
     * montant du bon tel qu'émis, même une fois utilisé ; null s'il n'en a
     * pas, comme tant qu'il est un brouillon.
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    public function mentionDuBonDAvoir(): ?string
    {
        return $this->numero === null ? null : $this->contexteSigne()['bon_d_avoir'];
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
        return $this->hasMany(LigneAvoir::class)->orderBy('ordre')->orderBy('id');
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
     * Le crédit de l'avoir qui reste à imputer : un avoir émis n'est jamais
     * remboursé (B5). À son émission, il règle les factures qu'il corrige,
     * puis les autres factures impayées de son client (voir
     * ImputerAvoirSurSesFacturesAction) ; ce qui reste s'applique ensuite
     * aux prochaines factures du même client (voir
     * AppliquerAvoirsDisponiblesAction et EnregistrerPaiementAction),
     * jusqu'à épuisement. Celui d'un avoir fait au client comptoir est son
     * bon d'avoir, que seule la présentation de son numéro impute sur une
     * vente à la caisse, quel qu'en soit le client (voir
     * EnregistrerVenteCaisseAction).
     */
    public function montantNonAlloue(): float
    {
        if ($this->statut !== StatutAvoir::Emise) {
            return 0.0;
        }

        return app(CalculateurLigne::class)->soustraire($this->total_ttc, $this->montantAlloue());
    }
}

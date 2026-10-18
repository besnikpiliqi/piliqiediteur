<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Concerns\HorodateSonEmission;
use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Concerns\EstEnvoyeParEmail;
use App\Domain\Facturation\Concerns\FigeLeClient;
use App\Domain\Facturation\Concerns\FigeSonEmetteur;
use App\Domain\Facturation\Concerns\NoteSaNatureDOperation;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\CopieClient;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Facturation\Services\PreuvesRequises;
use App\Domain\Facturation\Services\ReglementsALEmission;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Integrations\Pdp\Models\TransmissionPdp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\FactureFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * PAS de SoftDeletes ici, volontairement : une facture numérotée ne se
 * supprime jamais, même "doucement" -- la loi impose sa conservation, et
 * une correction passe par un avoir (voir EnregistrerAvoirAction). Un
 * brouillon, sans numéro ni existence légale, peut en revanche être
 * supprimé définitivement (voir FacturePolicy::delete()) : sa suppression
 * est une vente abandonnée avant validation, inscrite au journal des
 * événements (voir delete()).
 *
 * Une fois numérotée, elle ne se modifie plus que dans son statut de
 * règlement (voir ProtegeLesDonneesValidees) : elle est signée telle
 * qu'émise au registre fiscal (voir EmettreFactureAction), avec la copie de
 * son client et le type de ce client (voir FigeLeClient), l'émetteur
 * qu'elle imprime pour toujours (voir FigeSonEmetteur), sa nature
 * d'opération et sa livraison (voir NoteSaNatureDOperation). Ce qui la
 * suit sans la changer s'enregistre à côté, hors de son contenu signé :
 * les preuves de son exonération (voir preuvesOperation()) et, pour un
 * ticket, le n° du bordereau de détaxe (voir bordereauDetaxe()).
 */
class Facture extends Model implements DocumentEnvoyable
{
    use FigeLeNomDeSesAuteurs;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'cree_par' => 'cree_par_nom',
        ];
    }

    /** @use HasFactory<FactureFactory> */
    use BelongsToMagasin, EstEnvoyeParEmail, FigeLeClient, FigeSonEmetteur, HasFactory, HorodateSonEmission, NoteSaNatureDOperation, ProtegeLesDonneesValidees, VentileLaTvaParTaux;

    protected $table = 'factures';

    // numero, statut, date_emission, date_echeance, totaux, type et copie
    // du client, franchise de TVA, nature d'opération et livraison,
    // consultation VIES : jamais en mass assignment, uniquement via les
    // Actions dédiées (voir app/Domain/Facturation/Actions) -- l'échéance
    // et la consultation VIES se fixent à l'émission (voir
    // EmettreFactureAction).
    protected $fillable = [
        'client_id',
        'devis_id',
        'devise',
        'conditions_paiement',
        'notes',
    ];

    /**
     * La vente en France tant qu'aucune nature n'est notée, comme en base.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'nature_operation' => 'vente_france',
    ];

    protected $casts = [
        'statut' => StatutFacture::class,
        'franchise_en_base' => 'boolean',
        'nature_operation' => NatureOperation::class,
        'livraison_date' => 'date',
        'transport_par' => TransportPar::class,
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
        });

        // Après la garde de ProtegeLesDonneesValidees, qui refuse la
        // suppression d'une facture émise : seul un brouillon arrive ici.
        static::deleting(function (Facture $facture) {
            app(RegistreFiscal::class)->journaliser(CodeEvenement::TransactionAbandonnee, [
                'origine' => 'brouillon',
                'facture_id' => $facture->getKey(),
                'nombre_lignes' => $facture->lignes()->count(),
                'nombre_articles' => (int) $facture->lignes()->pluck('quantite')->sum(),
                'total_ttc' => app(CalculateurLigne::class)->enCentiemes($facture->total_ttc ?? '0.00'),
            ]);
        });
    }

    /**
     * Supprime le brouillon et inscrit au journal des événements, dans la
     * même transaction, sous le verrou du registre fiscal du magasin (voir
     * RegistreFiscal::sousVerrou()), la vente abandonnée avant validation
     * (JET 320) : son nombre de lignes, son nombre d'articles et son total
     * TTC en centimes. Si le journal ne s'écrit pas, le brouillon reste.
     * Une facture émise ne se supprime jamais (voir
     * ProtegeLesDonneesValidees).
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function delete(): ?bool
    {
        return app(RegistreFiscal::class)->sousVerrou($this->magasin, fn (): ?bool => parent::delete());
    }

    /**
     * Numérotée en base, donc émise.
     */
    public function estValidee(): bool
    {
        return $this->exists && $this->documentNumeroteEnBase(self::class, [$this->getKey()]);
    }

    /**
     * Son statut suit ses règlements (voir constaterUnReglement()), son
     * envoi (voir EnvoyerDocumentAction) et son échéance (voir
     * MarquerFacturesEnRetard).
     *
     * @return list<string>
     */
    protected function colonnesModifiablesUneFoisValidee(): array
    {
        return ['statut', 'updated_at'];
    }

    protected function regleDInalterabilite(): string
    {
        return 'Une facture ou un ticket émis ne se modifie plus : seul son statut de règlement évolue, et une correction passe par un avoir.';
    }

    /**
     * Un document légal garde son client, même archivé depuis (voir les
     * SoftDeletes de Client) : la facture reste due, se règle et se relance.
     * Elle imprime la copie de ce client prise à son émission (voir
     * copieClient()).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * La fiche du client, telle qu'elle est le jour de l'émission : celle
     * dont le type est contrôlé à l'émission (voir EmettreFactureAction).
     */
    protected function clientAFiger(): CopieClient
    {
        return CopieClient::depuisClient($this->client);
    }

    /**
     * La vérification sur VIES du n° de TVA du client que l'émission a
     * retenue pour une livraison intracommunautaire ou une prestation
     * autoliquidée (voir ControleNatureOperation) ; aucune pour une autre
     * nature, ni pour un brouillon.
     */
    public function consultationVies(): BelongsTo
    {
        return $this->belongsTo(ConsultationVies::class);
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
     * Les factures émises, quel que soit leur statut commercial depuis
     * (envoyée, payée, en retard...) : tout sauf les brouillons, qui n'ont
     * encore ni numéro ni existence légale. Une facture émise le reste
     * pour toujours, même corrigée par un avoir.
     */
    public function scopeEmises(Builder $query): Builder
    {
        return $query->where('statut', '!=', StatutFacture::Brouillon);
    }

    /**
     * Les factures et les tickets émis dont il reste quelque chose à
     * régler : émis, envoyés, partiellement payés ou en retard (voir
     * StatutFacture::quiAttendentUnReglement()), jamais un brouillon ni un
     * document payé. Une facture rouverte par la contre-passation d'un
     * paiement y revient (voir constaterUnReglement()) : l'alerte du
     * tableau de bord (voir FacturesAEncaisserApercu), le filtre « À
     * encaisser » de la liste des factures et les totaux de l'espace de la
     * société (voir FacturesAEncaisserDeLaSociete) la montrent.
     */
    public function scopeAEncaisser(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('statut'), StatutFacture::quiAttendentUnReglement());
    }

    /**
     * Le nombre et le restant dû de ces factures, dont celles en retard
     * (échéance dépassée, voir estEchue()), en centimes : chaque restant dû
     * se calcule ici, depuis les imputations chargées avec les factures
     * (voir resteDuEnCentimes()), jamais par une somme que la base ferait
     * sur une colonne décimale.
     *
     * @param  Collection<int, Facture>  $factures
     * @return array{nombre: int, restant_du: int, nombre_en_retard: int, restant_du_en_retard: int}
     */
    public static function totauxAEncaisser(Collection $factures): array
    {
        $enRetard = $factures->filter(fn (Facture $facture): bool => $facture->estEchue());
        $restantDu = fn (Collection $parmi): int => (int) $parmi->sum(fn (Facture $facture): int => $facture->resteDuEnCentimes());

        return [
            'nombre' => $factures->count(),
            'restant_du' => $restantDu($factures),
            'nombre_en_retard' => $enRetard->count(),
            'restant_du_en_retard' => $restantDu($enRetard),
        ];
    }

    /**
     * Les factures que cet utilisateur voit (B16), exactement comme
     * FacturePolicy::view() : toutes avec l'autorisation « voir celles de
     * toute l'équipe », celles qu'il a créées avec « voir (les siennes) »,
     * aucune sinon. Toute liste de factures, de leurs lignes ou de ce qu'il
     * en reste dû passe par ici : un montant visible dans un tableau ou un
     * menu est déjà une fuite, et une facture proposée doit être acceptée à
     * l'enregistrement.
     */
    public function scopeVisiblesPar(Builder $query, User $user): Builder
    {
        return match (true) {
            $user->can(PermissionRegistry::FACTURE_VIEW_TOUTES) => $query,
            $user->can(PermissionRegistry::FACTURE_VIEW) => $query->where('cree_par', $user->getKey()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Le ticket ou la facture de ce numéro, dans le magasin actif
     * (MagasinScope), saisi en majuscules ou en minuscules, avec ou sans
     * espaces autour ni les zéros de tête de son rang : « tk-lyon1-2026-12 »
     * trouve TK-LYON1-2026-000012 (voir
     * DocumentNumberingService::numeroSaisi()).
     */
    public function scopeDeNumero(Builder $query, string $numero): Builder
    {
        return $query->where($query->qualifyColumn('numero'), DocumentNumberingService::numeroSaisi($numero));
    }

    /**
     * Le jour, à l'heure de Paris (AAAA-MM-JJ), de la première facture ou
     * du premier ticket émis par le magasin actif : sa première journée de
     * caisse (voir CloturerLesJourneesAction). Null tant qu'il n'a rien
     * vendu.
     */
    public static function jourDeLaPremiereVente(): ?string
    {
        $premiere = static::query()->emises()->min('date_emission');

        return $premiere === null ? null : CarbonImmutable::parse($premiere, 'UTC')->setTimezone(config('app.timezone'))->toDateString();
    }

    /**
     * Les ventes d'un vendeur pas encore comptées dans une fermeture de sa
     * caisse : émises et jamais notées par une fermeture précédente --
     * quel que soit le moment où le brouillon a été créé, et donc son ID.
     */
    public function scopeNonFermeesParLeVendeur(Builder $query, int $vendeurId): Builder
    {
        return $query
            ->emises()
            ->where('cree_par', $vendeurId)
            ->whereDoesntHave('cloturesCaissePersonnelles');
    }

    /**
     * Les tickets de caisse : les ventes émises à un particulier, numérotées
     * dans la série des tickets (voir serie()). Le type du client figé à
     * l'émission le dit pour toujours, même si la fiche du client change
     * depuis : un ticket reste dans la clôture de sa journée, et une
     * facture à un professionnel n'y entre pas.
     */
    public function scopeTickets(Builder $query): Builder
    {
        return $query->emises()->where('type_client', TypeClient::Particulier);
    }

    /**
     * Les tickets émis tel jour qu'aucune clôture n'a encore comptés (voir
     * CloturerCaisseAction) -- jamais un brouillon, jamais une facture à un
     * professionnel. Un ticket corrigé depuis par un avoir y figure :
     * l'avoir se déduit à part, le jour de son émission.
     */
    public function scopeEnAttenteDeCloture(Builder $query, CarbonInterface|string $jour): Builder
    {
        return $query
            ->tickets()
            ->emisLe($jour)
            ->whereNull('cloture_caisse_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneFacture::class)->orderBy('ordre')->orderBy('id');
    }

    /**
     * Les références des preuves qui justifient son exonération (I4, voir
     * PreuvesRequises), de la première enregistrée à la dernière.
     */
    public function preuvesOperation(): HasMany
    {
        return $this->hasMany(PreuveOperation::class)->orderBy('id');
    }

    /**
     * Les n° de bordereau de détaxe notés sur ce ticket (I3, voir
     * NoterBordereauDetaxeAction), du premier noté au dernier : une faute
     * de saisie se corrige en notant le bon n°.
     */
    public function bordereauxDetaxe(): HasMany
    {
        return $this->hasMany(BordereauDetaxe::class)->orderBy('id');
    }

    /**
     * Le n° de bordereau de détaxe qui fait foi : le dernier noté.
     */
    public function bordereauDetaxe(): HasOne
    {
        return $this->hasOne(BordereauDetaxe::class)->latestOfMany();
    }

    /**
     * Les factures émises dont l'exonération attend encore une preuve
     * (voir PreuvesRequises::incompletes()) : le filtre et le badge
     * « Preuves à fournir » de la liste des factures.
     */
    public function scopePreuvesAFournir(Builder $query): Builder
    {
        return app(PreuvesRequises::class)->incompletes($query);
    }

    public function transmissionsPdp(): MorphMany
    {
        return $this->morphMany(TransmissionPdp::class, 'transmissible');
    }

    /**
     * Ses imputations de paiements, dans l'ordre où elles ont été
     * enregistrées (un paiement saisi par erreur, puis sa
     * contre-passation) : la base de données ne garantit aucun ordre sans
     * tri, et la fiche les montre dans celui-ci (voir FactureResource).
     */
    public function allocationsPaiement(): HasMany
    {
        return $this->hasMany(PaiementAllocation::class)->orderBy('id');
    }

    /**
     * Ses imputations de crédit d'avoir, dans l'ordre où elles ont été
     * enregistrées (voir allocationsPaiement()).
     */
    public function allocationsAvoir(): HasMany
    {
        return $this->hasMany(AvoirAllocation::class)->orderBy('id');
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
     * pas encore revenu par un avoir sur l'une de ses lignes (voir
     * RestaurerStockApresEmissionAvoir) : jamais plus ne peut y revenir. Un
     * article vendu sans suivi du stock n'en est jamais sorti.
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
     * Crédit d'avoir imputé sur cette facture : par un avoir à son émission,
     * qu'il la corrige ou non (voir ImputerAvoirSurSesFacturesAction), à
     * l'émission de la facture, crédit du client ou bons d'avoir présentés
     * à la caisse (voir AppliquerAvoirsDisponiblesAction et
     * EmettreFactureAction), par les bons présentés avec un paiement (voir
     * EnregistrerPaiementAction) ou par la contre-passation d'un paiement
     * qui l'a rouverte (voir ContrePasserPaiementAction), moins celui
     * qu'une contre-passation a rendu -- jamais un paiement réellement
     * encaissé, mais il réduit le solde dû exactement de la même façon.
     */
    public function montantCrediteParAvoir(): float
    {
        return app(CalculateurLigne::class)->sommer($this->allocationsAvoir()->pluck('montant_alloue'));
    }

    /**
     * Les numéros des avoirs dont le crédit reste imputé sur cette facture,
     * chacun une fois, du premier imputé au dernier : un avoir peut s'y
     * imputer plusieurs fois (à son émission, puis par un paiement), et le
     * crédit qu'une contre-passation a rendu à son avoir n'y compte plus.
     *
     * @return list<string>
     */
    public function numerosDesAvoirsImputes(): array
    {
        $calculateur = app(CalculateurLigne::class);

        return $this->allocationsAvoir
            ->sortBy('id')
            ->groupBy('avoir_id')
            ->filter(fn (Collection $allocations): bool => $calculateur->sommer($allocations->pluck('montant_alloue')) > 0)
            ->map(fn (Collection $allocations): ?string => $allocations->first()->avoir?->numero)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Ce qui a réglé la facture à son émission, relu dans son contenu
     * signé, que son PDF imprime (Q5) ; rien pour un brouillon. Ce qu'elle
     * reçoit ensuite se lit dans ses imputations (voir montantPaye() et
     * montantCrediteParAvoir()).
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    public function reglementsALEmission(): ReglementsALEmission
    {
        return ReglementsALEmission::depuisLeContexte(
            $this->numero === null ? null : $this->contexteSigne(),
            app(CalculateurLigne::class)->enCentiemes($this->total_ttc),
        );
    }

    public function montantRestant(): float
    {
        return app(CalculateurLigne::class)->soustraire($this->total_ttc, $this->montantPaye(), $this->montantCrediteParAvoir());
    }

    /**
     * Ce que la facture a reçu, paiements et crédit d'avoir compris (une
     * contre-passation en déduction), en centimes : la somme, faite ici,
     * de ses imputations. Une liste de factures les charge avec elles
     * (voir FactureResource::table()), pour ne pas les relire une à une.
     */
    public function regleEnCentimes(): int
    {
        $calculateur = app(CalculateurLigne::class);

        return (int) $this->allocationsPaiement->concat($this->allocationsAvoir)->sum(
            fn (PaiementAllocation|AvoirAllocation $allocation): int => $calculateur->enCentiemes($allocation->montant_alloue),
        );
    }

    /**
     * Ce qu'il reste à régler, en centimes : son total TTC moins ce
     * qu'elle a reçu (voir regleEnCentimes()).
     */
    public function resteDuEnCentimes(): int
    {
        return app(CalculateurLigne::class)->enCentiemes($this->total_ttc) - $this->regleEnCentimes();
    }

    /**
     * Après un paiement, sa contre-passation ou l'imputation d'un avoir, le
     * statut suit ce qui reste dû : « payée » quand plus rien ne l'est ;
     * sinon « en retard » une fois l'échéance dépassée, tant qu'il reste un
     * montant dû (G1) ; sinon « partiellement payée » si elle a déjà reçu
     * un paiement ou un crédit ; sinon, rouverte par une contre-passation,
     * elle redevient « envoyée » si elle est partie au client, « émise »
     * sinon.
     *
     * @throws LogicException
     */
    public function constaterUnReglement(): void
    {
        $calculateur = app(CalculateurLigne::class);
        $nouveauStatut = match (true) {
            $this->montantRestant() <= 0.0 => StatutFacture::Payee,
            $this->estEchue() => StatutFacture::EnRetard,
            $calculateur->sommer([$this->montantPaye(), $this->montantCrediteParAvoir()]) > 0.0 => StatutFacture::PartiellementPayee,
            $this->envois()->where('statut', '!=', StatutEnvoi::Echec)->exists() => StatutFacture::Envoyee,
            default => StatutFacture::Emise,
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
     * Son échéance est dépassée : elle tombait avant aujourd'hui. Une
     * facture émise a toujours son échéance (voir EmettreFactureAction) :
     * seul un document numéroté directement en base, que signale la
     * vérification de l'intégrité, peut ne pas en avoir.
     */
    public function estEchue(): bool
    {
        return $this->date_echeance !== null && $this->date_echeance->lt(today());
    }

    /**
     * Une facture se relance (voir EnvoyerDocumentAction::relancer()) une
     * fois son échéance passée, tant qu'il reste quelque chose à régler --
     * jamais une facture payée ou soldée par un avoir. Une facture sans
     * échéance (voir estEchue()) ne se relance pas, et la liste des
     * factures comme sa fiche s'affichent quand même.
     */
    public function estEchueEtImpayee(): bool
    {
        return $this->statut->attendUnReglement()
            && $this->estEchue()
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
        [$document, $accord] = $this->estPourUnProfessionnel() ? ['la facture', 'e'] : ['le ticket de caisse', ''];

        return "Sauf erreur de notre part, {$document} {$this->numero} d'un montant de {$euros((float) $this->total_ttc)} TTC, "
            ."arrivé{$accord} à échéance le {$this->date_echeance->format('d/m/Y')}, n'est pas entièrement réglé{$accord} : "
            ."il reste {$euros($this->montantRestant())} à payer.\n"
            .'Nous vous remercions de bien vouloir procéder à son règlement dans les meilleurs délais. '
            ."Si vous l'avez réglé{$accord} entre-temps, merci de ne pas tenir compte de ce message.";
    }

    /**
     * Une vente à un particulier porte le libellé usuel « Ticket de
     * caisse », une vente à un professionnel celui de « Facture », d'après
     * le type du client écrit sur le document (voir serie()).
     */
    public function libelleDocument(): string
    {
        return $this->estPourUnProfessionnel() ? 'Facture' : 'Ticket de caisse';
    }

    /**
     * Seule une facture émise existe pour son client : un brouillon n'a
     * pas encore de numéro. Une fois émise, elle le reste pour toujours,
     * même corrigée par un avoir.
     */
    public function peutEtreEnvoye(): bool
    {
        return $this->statut !== StatutFacture::Brouillon;
    }

    /**
     * La série où la vente est numérotée à son émission (voir
     * EmettreFactureAction) : les tickets de caisse pour un particulier, les
     * factures pour un professionnel, chacune avec son circuit fiscal
     * (clôture de caisse et e-reporting, ou facture électronique) et jamais
     * mélangées dans un même avoir (voir CreerAvoirAction). Elle suit le
     * type du client écrit sur le document, figé à l'émission : le premier
     * segment du numéro la porte aussi (TK-LYON1-2026-000001 est un ticket).
     */
    public function serie(): TypeDocument
    {
        return $this->estPourUnProfessionnel() ? TypeDocument::Facture : TypeDocument::TicketCaisse;
    }

    /**
     * Le circuit de la vente, d'après le type du client figé sur le
     * document : une facture à un professionnel se transmet une à une à la
     * plateforme agréée (facture électronique), un ticket à un particulier
     * entre dans la clôture de sa journée et dans l'e-reporting agrégé.
     * Simplification provisoire : l'intégration de SUPER PDP décidera du
     * circuit depuis le client figé (type, établissement en France, pays)
     * et la nature de l'opération, puisqu'un professionnel établi hors de
     * France relève de l'e-reporting des opérations internationales et
     * qu'un client public ou non assujetti reste hors plateforme.
     */
    public function relevantDeLeInvoicing(): bool
    {
        return $this->estPourUnProfessionnel();
    }
}

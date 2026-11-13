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
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Calendrier\Casts\JourCivil;
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
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\CopieClient;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Facturation\Services\PreuvesRequises;
use App\Domain\Facturation\Services\ReglementsALEmission;
use App\Domain\Facturation\Services\RegularisationDUneExoneration;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Integrations\Pdp\Models\TransmissionPdp;
use App\Models\User;
use Database\Factories\FactureFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
 *
 * Après la correction du SIREN de la société par l'éditeur, une facture
 * que désigne son examen se régularise (voir RegularisationDUneFacture) :
 * un avoir de régularisation crédite ce qui reste à créditer de cette
 * facture, ce que les avoirs déjà émis n'en ont pas crédité, et une
 * facture rectificative (voir factureRectifiee()) en reprend les lignes
 * sous l'identité corrigée de la société.
 */
class Facture extends Model implements DocumentEnvoyable
{
    use FigeLeNomDeSesAuteurs;

    public const REFUS_SUPPRESSION = 'Ce brouillon a été émis entre-temps : une facture émise ne se supprime plus, elle se corrige par un avoir.';

    public const REFUS_INTROUVABLE = 'Cette facture n\'existe pas, ou plus, dans ce magasin.';

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
        'date_emission' => Instant::class,
        'type_client' => TypeClient::class,
        'client_etabli_en_france' => 'boolean',
        'franchise_en_base' => 'boolean',
        'nature_operation' => NatureOperation::class,
        'livraison_date' => JourCivil::class,
        'transport_par' => TransportPar::class,
        'date_echeance' => JourCivil::class,
        'devis_accepte_le' => JourCivil::class,
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

        // Après delete(), qui relit le brouillon sous verrou, et la garde
        // de ProtegeLesDonneesValidees, qui refuse la suppression d'une
        // facture émise : seul un brouillon encore en base arrive ici,
        // avec ses montants en base.
        static::deleting(function (Facture $facture) {
            app(RegistreFiscal::class)->journaliser(CodeEvenement::TransactionAbandonnee, [
                'origine' => 'brouillon',
                'facture_id' => $facture->getKey(),
                'nombre_lignes' => $facture->lignes()->count(),
                'nombre_articles' => ValeursLues::entier($facture->lignes()->pluck('quantite')->sum()),
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
     *
     * Le dernier rempart, derrière SupprimerFactureAction : la facture se
     * relit en base sous le verrou du registre puis sous le sien, et cette
     * instance prend ses valeurs en base. Un brouillon déjà supprimé par
     * une autre requête est refusé, et un brouillon émis entre-temps (voir
     * EmettreFactureAction, qui prend le même verrou du registre) l'est par
     * la garde des documents numérotés (voir ProtegeLesDonneesValidees) :
     * une seule vente abandonnée s'inscrit pour un brouillon, avec les
     * montants qu'il a au moment de sa suppression, même modifié depuis le
     * chargement de cette instance, et une facture émise ne se supprime
     * jamais. Le brouillon d'une facture transformée d'un devis rend à ce
     * devis son statut « Accepté », relu sous son verrou : la
     * transformation est abandonnée, et le devis peut de nouveau se
     * transformer (voir CreerFactureDepuisDevisAction).
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function delete(): ?bool
    {
        return app(RegistreFiscal::class)->sousVerrou($this->magasin, function (): ?bool {
            $enBase = static::query()->whereKey($this->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(self::REFUS_INTROUVABLE);

            $this->setRawAttributes($enBase->getAttributes(), true);
            $supprimee = parent::delete();

            if ($this->devis_id !== null) {
                $devis = Devis::query()->whereKey($this->devis_id)->lockForUpdate()->first();

                if ($devis?->statut === StatutDevis::Converti) {
                    $devis->forceFill(['statut' => StatutDevis::Accepte])->save();
                }
            }

            return $supprimee;
        });
    }

    /**
     * Numérotée en base, donc émise.
     */
    public function estValidee(): bool
    {
        return $this->exists && $this->documentNumeroteEnBase(self::class, [CleDuModele::de($this)]);
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
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * La fiche du client, telle qu'elle est le jour de l'émission : celle
     * dont le type est contrôlé à l'émission (voir EmettreFactureAction).
     * Une facture rectificative, elle, reprend la copie que la facture
     * qu'elle rectifie a figée : elle ne corrige que l'identité du vendeur.
     */
    protected function clientAFiger(): CopieClient
    {
        return $this->estUneRectificative()
            ? $this->factureRectifiee()->firstOrFail()->copieClient()
            : CopieClient::depuisClient(ValeurRequise::de($this->client, 'le client de la facture'));
    }

    /**
     * Le devis du même magasin d'après lequel la facture est établie, quand
     * elle a été transformée d'un devis accepté (voir
     * CreerFactureDepuisDevisAction) ; aucun sinon.
     *
     * @return BelongsTo<Devis, $this>
     */
    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    /**
     * Ce que la fiche et le PDF disent du devis d'origine, depuis le numéro
     * et le jour d'acceptation recopiés à la transformation et figés à
     * l'émission : « Établie d'après le devis n° DEV-… accepté le
     * JJ/MM/AAAA » ; null pour une facture qui ne vient pas d'un devis.
     */
    public function mentionDuDevis(): ?string
    {
        return $this->devis_numero === null || $this->devis_accepte_le === null
            ? null
            : "Établie d'après le devis n° {$this->devis_numero} accepté le ".Calendrier::afficherJour($this->devis_accepte_le, 'd/m/Y');
    }

    /**
     * La facture du même magasin que rectifie cette facture rectificative,
     * émise après la correction du SIREN de la société par l'éditeur (voir
     * EmettreLaFactureRectificativeAction) ou pour une exonération non
     * prouvée (voir RegulariserUneExonerationNonProuveeAction) ; aucune
     * pour toute autre facture.
     *
     * @return BelongsTo<self, $this>
     */
    public function factureRectifiee(): BelongsTo
    {
        return $this->belongsTo(self::class, 'facture_rectifiee_id');
    }

    /**
     * La facture rectificative de cette facture, quand elle en a une : au
     * plus une (index unique).
     *
     * @return HasOne<self, $this>
     */
    public function rectificative(): HasOne
    {
        return $this->hasOne(self::class, 'facture_rectifiee_id');
    }

    /**
     * Est-ce la facture rectificative d'une facture que l'examen du SIREN
     * de la société a désignée, ou d'une exonération non prouvée ? Elle
     * reprend les lignes de cette facture (voir
     * RegularisationDUneFacture::lignesDeLaRectificative() et
     * RegularisationDUneExoneration::lignesALaTvaFrancaise()), sans sortie
     * de stock (voir DecrementerStockApresEmissionFacture).
     */
    public function estUneRectificative(): bool
    {
        return $this->facture_rectifiee_id !== null;
    }

    /**
     * La vérification sur VIES du n° de TVA du client que l'émission a
     * retenue pour une livraison intracommunautaire ou une prestation
     * autoliquidée (voir ControleNatureOperation) ; aucune pour une autre
     * nature, ni pour un brouillon.
     *
     * @return BelongsTo<ConsultationVies, $this>
     */
    public function consultationVies(): BelongsTo
    {
        return $this->belongsTo(ConsultationVies::class);
    }

    /**
     * Fermetures de caisse personnelles qui ont compté cette vente (une
     * seule au plus, voir ClotureCaissePersonnelle::factures()).
     *
     * @return BelongsToMany<ClotureCaissePersonnelle, $this>
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
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
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
     * paiement y revient (voir constaterUnReglement()) : l'« À faire » du
     * tableau de bord du magasin (voir ChosesAFaireDuMagasin), le filtre
     * « À encaisser » de la liste des factures et les totaux de l'espace de
     * la société (voir FacturesAEncaisserDeLaSociete) la montrent.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
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

        return [
            'nombre' => $factures->count(),
            'restant_du' => self::restantDu($factures),
            'nombre_en_retard' => $enRetard->count(),
            'restant_du_en_retard' => self::restantDu($enRetard),
        ];
    }

    /**
     * Ce qui reste dû de ces factures, en centimes (voir
     * resteDuEnCentimes()).
     *
     * @param  Collection<int, Facture>  $factures
     */
    private static function restantDu(Collection $factures): int
    {
        return array_sum(array_map(fn (Facture $facture): int => $facture->resteDuEnCentimes(), $factures->all()));
    }

    /**
     * Les factures que cet utilisateur voit (B16), exactement comme
     * FacturePolicy::view() : toutes avec l'autorisation « voir celles de
     * toute l'équipe », celles qu'il a créées avec « voir (les siennes) »,
     * aucune sinon. Toute liste de factures, de leurs lignes ou de ce qu'il
     * en reste dû passe par ici : un montant visible dans un tableau ou un
     * menu est déjà une fuite, et une facture proposée doit être acceptée à
     * l'enregistrement.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
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
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDeNumero(Builder $query, string $numero): Builder
    {
        return $query->where($query->qualifyColumn('numero'), DocumentNumberingService::numeroSaisi($numero));
    }

    /**
     * Le jour, dans le calendrier du magasin actif, de la première facture
     * ou du premier ticket qu'il a émis (AAAA-MM-JJ) : sa première journée
     * de caisse (voir CloturerLesJourneesAction). Null tant qu'il n'a rien
     * vendu.
     */
    public static function jourDeLaPremiereVente(Calendrier $calendrier): ?string
    {
        $premiere = static::query()->emises()->min('date_emission');

        return $premiere === null ? null : $calendrier->jourDe(Instant::depuisLaBase(ValeursLues::texte($premiere)));
    }

    /**
     * Les ventes d'un vendeur pas encore comptées dans une fermeture de sa
     * caisse : émises et jamais notées par une fermeture précédente --
     * quel que soit le moment où le brouillon a été créé, et donc son ID.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
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
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTickets(Builder $query): Builder
    {
        return $query->emises()->where('type_client', TypeClient::Particulier);
    }

    /**
     * Les tickets émis tel jour du calendrier de leur magasin qu'aucune
     * clôture n'a encore comptés (voir CloturerCaisseAction) -- jamais un
     * brouillon, jamais une facture à un professionnel. Un ticket corrigé
     * depuis par un avoir y figure : l'avoir se déduit à part, le jour de
     * son émission.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEnAttenteDeCloture(Builder $query, Calendrier $calendrier, string $jour): Builder
    {
        return $query
            ->tickets()
            ->emisLe($calendrier, $jour)
            ->whereNull('cloture_caisse_id');
    }

    /**
     * @return HasMany<LigneFacture, $this>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneFacture::class)->orderBy('ordre')->orderBy('id');
    }

    /**
     * Les références des preuves qui justifient son exonération (I4, voir
     * PreuvesRequises), de la première enregistrée à la dernière.
     *
     * @return HasMany<PreuveOperation, $this>
     */
    public function preuvesOperation(): HasMany
    {
        return $this->hasMany(PreuveOperation::class)->orderBy('id');
    }

    /**
     * Les n° de bordereau de détaxe notés sur ce ticket (I3, voir
     * NoterBordereauDetaxeAction), du premier noté au dernier : une faute
     * de saisie se corrige en notant le bon n°.
     *
     * @return HasMany<BordereauDetaxe, $this>
     */
    public function bordereauxDetaxe(): HasMany
    {
        return $this->hasMany(BordereauDetaxe::class)->orderBy('id');
    }

    /**
     * Le n° de bordereau de détaxe qui fait foi : le dernier noté.
     *
     * @return HasOne<BordereauDetaxe, $this>
     */
    public function bordereauDetaxe(): HasOne
    {
        return $this->hasOne(BordereauDetaxe::class)->latestOfMany();
    }

    /**
     * Les factures émises dont l'exonération attend encore une preuve
     * (voir PreuvesRequises::incompletes()) : l'« À faire » du tableau de
     * bord du magasin (voir ChosesAFaireDuMagasin), ses nombres dans
     * l'espace de la société, et le filtre « Preuves à fournir » et la
     * colonne « À faire » de la liste des factures.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePreuvesAFournir(Builder $query): Builder
    {
        return app(PreuvesRequises::class)->incompletes($query);
    }

    /**
     * Les exports hors de l'Union et les livraisons vers un DOM sans preuve
     * de sortie qui se régularisent ce jour (AAAA-MM-JJ, dans ce
     * calendrier, celui de leur magasin) : 150 jours après leur date (voir
     * RegularisationDUneExoneration::aRegulariser()). Le filtre «
     * Exonération non prouvée » de la liste des factures et l'« À faire »
     * du tableau de bord du magasin.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExonerationsNonProuvees(Builder $query, Calendrier $calendrier, string $aujourdhui): Builder
    {
        return app(RegularisationDUneExoneration::class)->aRegulariser($query, $calendrier, $aujourdhui);
    }

    /**
     * Les tickets détaxés dont le bordereau qui fait foi, le dernier noté
     * (voir bordereauDetaxe()), attend encore la date de son visa à la
     * sortie de l'Union (décision de l'éditeur du 01/11/2026, Q-AF13, voir
     * NoterLeVisaDuBordereauAction) : le filtre « Visa à noter » de la
     * liste des factures, l'« À faire » du tableau de bord du magasin et
     * ses nombres dans l'espace de la société. Seul un ticket qui garde au
     * moins un bien taxé qu'aucun avoir émis n'a entièrement repris attend
     * son visa, comme pour noter son bordereau (voir
     * NoterBordereauDetaxeAction::refusPour()) : rendu en entier, aucune
     * marchandise ne sort de l'Union et aucun visa ne viendra. Les
     * bordereaux, leurs visas et les lignes se lisent dans le magasin de
     * chaque ticket (voir BelongsToMagasin::scopeDuMagasinDe()) : la
     * condition vaut dans le magasin actif comme pour plusieurs magasins de
     * la société (voir ChosesAFaireDeLaSociete).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisaANoter(Builder $query): Builder
    {
        $magasin = $query->qualifyColumn('magasin_id');
        $dernierNote = BordereauDetaxe::query()
            ->duMagasinDe($magasin)
            ->whereColumn('bordereaux_detaxe.facture_id', $query->qualifyColumn('id'))
            ->selectRaw('max(bordereaux_detaxe.id)');

        return $query
            ->whereHas('bordereauxDetaxe', fn (Builder $bordereaux): Builder => $bordereaux
                ->duMagasinDe($magasin)
                ->where('bordereaux_detaxe.id', $dernierNote)
                ->whereDoesntHave('visa', fn (Builder $visas): Builder => $visas->duMagasinDe('bordereaux_detaxe.magasin_id')))
            ->whereHas('lignes', fn (Builder $lignes): Builder => $lignes->duMagasinDe($magasin)->creditables()->biensTaxes());
    }

    /**
     * Elle-même : une relation de la facture vers sa propre ligne, pour
     * qu'une requête de plusieurs factures lise en une seule sous-requête
     * corrélée une condition écrite comme un scope (voir
     * FactureResource::getEloquentQuery(), withExists() sur cette
     * relation) ; Eloquent y renomme la table de la sous-requête, si bien
     * que le scope s'y lit tel quel.
     *
     * @return HasOne<Facture, $this>
     */
    public function elleMeme(): HasOne
    {
        return $this->hasOne(self::class, 'id', 'id');
    }

    /**
     * Ce ticket détaxé attend-il la date du visa de son bordereau (voir
     * scopeVisaANoter()) ? Lu par la requête de la liste des factures
     * quand elle l'a chargé (voir FactureResource::getEloquentQuery()),
     * sinon relu en base.
     */
    public function attendSonVisa(): bool
    {
        if (array_key_exists('visa_a_noter', $this->getAttributes())) {
            return (bool) $this->getAttribute('visa_a_noter');
        }

        return $this->statut !== StatutFacture::Brouillon && static::query()->whereKey($this->getKey())->visaANoter()->exists();
    }

    /**
     * Cette vente exonérée se régularise-t-elle aujourd'hui, faute de
     * preuve de sortie 150 jours après sa date (voir
     * scopeExonerationsNonProuvees()) ? Lu par la requête de la liste des
     * factures quand elle l'a chargé (voir
     * FactureResource::getEloquentQuery()) ; sinon relu en base, sans rien
     * lire pour une facture d'une autre nature, la plupart.
     */
    public function attendLaRegularisationDeSonExoneration(): bool
    {
        if (array_key_exists('exoneration_non_prouvee', $this->getAttributes())) {
            return (bool) $this->getAttribute('exoneration_non_prouvee');
        }

        $calendrier = $this->magasin->calendrier();

        return $this->statut !== StatutFacture::Brouillon
            && in_array($this->nature_operation, RegularisationDUneExoneration::NATURES, true)
            && static::query()->whereKey($this->getKey())->exonerationsNonProuvees($calendrier, $calendrier->aujourdhui())->exists();
    }

    /**
     * Cette facture émise attend-elle encore une preuve de son exonération
     * (voir scopePreuvesAFournir()) ? Lu par la requête de la liste des
     * factures quand elle l'a chargé (voir FactureResource::getEloquentQuery()) ;
     * sinon relu en base, sans rien lire pour une facture d'une nature qui
     * n'en demande aucune, la plupart.
     */
    public function attendUnePreuve(): bool
    {
        if (array_key_exists('preuve_a_fournir', $this->getAttributes())) {
            return (bool) $this->getAttribute('preuve_a_fournir');
        }

        return $this->statut !== StatutFacture::Brouillon
            && PreuvesRequises::typesAdmis($this->nature_operation) !== []
            && static::query()->whereKey($this->getKey())->preuvesAFournir()->exists();
    }

    /**
     * @return MorphMany<TransmissionPdp, $this>
     */
    public function transmissionsPdp(): MorphMany
    {
        return $this->morphMany(TransmissionPdp::class, 'transmissible');
    }

    /**
     * Sa dernière transmission à la plateforme agréée, celle dont l'état
     * vaut pour la facture, lue dans ses transmissions chargées.
     */
    public function derniereTransmissionPdp(): ?TransmissionPdp
    {
        return $this->transmissionsPdp->where('direction', 'outbound')->sortByDesc('id')->first();
    }

    /**
     * Ses imputations de paiements, dans l'ordre où elles ont été
     * enregistrées (un paiement saisi par erreur, puis sa
     * contre-passation) : la base de données ne garantit aucun ordre sans
     * tri, et la fiche les montre dans celui-ci (voir FactureResource).
     *
     * @return HasMany<PaiementAllocation, $this>
     */
    public function allocationsPaiement(): HasMany
    {
        return $this->hasMany(PaiementAllocation::class)->orderBy('id');
    }

    /**
     * Ses imputations de crédit d'avoir, dans l'ordre où elles ont été
     * enregistrées (voir allocationsPaiement()).
     *
     * @return HasMany<AvoirAllocation, $this>
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
     *
     * @return Builder<Avoir>
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
     * pas été intégralement créditée. Ils se lisent dans l'ordre de leur
     * émission, que la fiche de la facture suit (voir FactureResource) :
     * par date d'émission, un instant fiscal qui ne recule jamais dans le
     * magasin (voir InstantFiscal), puis par numéro, donné sous verrou à
     * l'émission, pour deux avoirs émis dans la même seconde. La base de
     * données ne garantit aucun ordre sans tri : un avoir modifié après
     * son émission (sa clôture) change de place dans sa table, et un
     * brouillon créé avant un autre peut être émis après lui.
     *
     * @return Builder<Avoir>
     */
    public function avoirsEmisConcernes(): Builder
    {
        return $this->avoirsConcernes()
            ->where('statut', StatutAvoir::Emise)
            ->orderBy('date_emission')
            ->orderBy('numero');
    }

    /**
     * Ce que la vente de cette facture a fait sortir du stock pour ce
     * produit (voir DecrementerStockApresEmissionFacture) et qui n'y est pas
     * encore revenu par un avoir (voir RestaurerStockApresEmissionAvoir) :
     * jamais plus ne peut y revenir. Un article vendu sans suivi du stock
     * n'en est jamais sorti. Une facture rectificative, après la correction
     * du SIREN de la société, ne sort rien du stock : elle reprend la vente
     * de la facture qu'elle rectifie, dont les articles sont déjà sortis
     * (voir RegularisationDUneFacture). La vente se compte donc depuis la
     * première facture de cette suite, et les retours sur les lignes de
     * chacune de ses factures : un retour sur la rectificative rend ce que
     * la facture rectifiée a sorti et que ni ses avoirs ni ceux de la
     * rectificative n'ont déjà rendu.
     */
    public function quantiteSortieDuStock(Produit $produit): int
    {
        $factures = $this->facturesDeLaMemeVente();
        $mouvements = MouvementStock::query()
            ->where('produit_id', $produit->getKey())
            ->where(fn (Builder $mouvements) => $mouvements
                ->where(fn (Builder $ventes) => $ventes->where('source_type', $this->getMorphClass())->whereIn('source_id', $factures))
                ->orWhereHasMorph('source', [LigneAvoir::class], fn (Builder $ligneAvoir) => $ligneAvoir
                    ->whereHas('ligneFacture', fn (Builder $ligneFacture) => $ligneFacture->whereIn('facture_id', $factures))))
            ->get(['type', 'quantite']);

        return -$mouvements->sum(fn (MouvementStock $mouvement): int => $mouvement->type->variation($mouvement->quantite));
    }

    /**
     * Les identifiants des factures d'une même vente, de la première à la
     * dernière : cette facture, la facture qu'elle rectifie, et ainsi de
     * suite, puis sa rectificative et la rectificative de celle-ci (une
     * facture n'en a qu'une, une seconde correction du SIREN pouvant
     * rectifier une rectificative). Une facture qui n'a jamais été
     * régularisée est seule de sa vente.
     *
     * @return list<int>
     */
    private function facturesDeLaMemeVente(): array
    {
        $precedentes = [];

        for ($facture = $this; $facture->facture_rectifiee_id !== null; $facture = $facture->factureRectifiee()->firstOrFail()) {
            array_unshift($precedentes, (int) $facture->facture_rectifiee_id);
        }

        $suivantes = [];

        for ($facture = $this; ($rectificative = $facture->rectificative()->first(['id', 'facture_rectifiee_id'])) !== null; $facture = $rectificative) {
            $suivantes[] = CleDuModele::de($rectificative);
        }

        return [...$precedentes, CleDuModele::de($this), ...$suivantes];
    }

    public function possedeDesLignesCreditables(): bool
    {
        return array_key_exists('lignes_creditables_exists', $this->attributes)
            ? (bool) $this->attributes['lignes_creditables_exists']
            : $this->lignes()->creditables()->exists();
    }

    /**
     * Ce que les paiements imputés sur cette facture ont réglé, en
     * centimes, contre-passations déduites, relu en base à chaque appel.
     */
    public function montantPayeEnCentimes(): int
    {
        return app(CalculateurLigne::class)->sommer(ValeursLues::textes($this->allocationsPaiement()->pluck('montant_alloue')));
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
     * encaissé, mais il réduit le solde dû exactement de la même façon. En
     * centimes, relu en base à chaque appel.
     */
    public function montantCrediteParAvoirEnCentimes(): int
    {
        return app(CalculateurLigne::class)->sommer(ValeursLues::textes($this->allocationsAvoir()->pluck('montant_alloue')));
    }

    /**
     * Ce qui a réglé la facture à son émission, relu dans son contenu
     * signé, que son PDF imprime (Q5) ; rien pour un brouillon. Ce qu'elle
     * reçoit ensuite se lit dans ses imputations (voir
     * montantPayeEnCentimes() et montantCrediteParAvoirEnCentimes()).
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

    /**
     * Ce qu'il reste à régler, en centimes : son total TTC moins ses
     * paiements et son crédit d'avoir, relus en base à chaque appel (voir
     * resteDuEnCentimes(), qui lit les imputations chargées avec une liste
     * de factures).
     */
    public function montantRestantEnCentimes(): int
    {
        return app(CalculateurLigne::class)->enCentiemes($this->total_ttc) - $this->montantPayeEnCentimes() - $this->montantCrediteParAvoirEnCentimes();
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
        $nouveauStatut = match (true) {
            $this->montantRestantEnCentimes() <= 0 => StatutFacture::Payee,
            $this->estEchue() => StatutFacture::EnRetard,
            $this->montantPayeEnCentimes() + $this->montantCrediteParAvoirEnCentimes() > 0 => StatutFacture::PartiellementPayee,
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
     * Son échéance est dépassée : elle tombait avant aujourd'hui, jour du
     * calendrier de son magasin, quel que soit le magasin actif (une
     * tâche, un envoi en file d'attente, l'espace de la société). Une
     * facture émise a toujours son échéance (voir EmettreFactureAction) :
     * seul un document numéroté directement en base, que signale la
     * vérification de l'intégrité, peut ne pas en avoir.
     */
    public function estEchue(): bool
    {
        return $this->date_echeance !== null && $this->date_echeance < $this->magasin->calendrier()->aujourdhui();
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
            && $this->montantRestantEnCentimes() > 0;
    }

    /**
     * Les relances de cette facture parties ou en partance : une relance
     * en échec n'a jamais atteint le client, elle ne compte pas et repart
     * à la relance suivante de la facture (voir
     * EnvoyerDocumentAction::relancer()).
     *
     * @return MorphMany<EnvoiDocument, $this>
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
        return ValeurSimple::enEntier($this->relancesComptees()->max('niveau_relance'));
    }

    /**
     * Le nombre de jours du calendrier de son magasin depuis sa dernière
     * relance (0 si elle a été relancée aujourd'hui), null tant qu'elle n'a
     * jamais été relancée.
     */
    public function joursDepuisLaDerniereRelance(): ?int
    {
        $derniere = $this->relancesComptees()->latest('created_at')->latest('id')->first();
        $calendrier = $this->magasin->calendrier();

        return $derniere === null ? null : Calendrier::joursEntre($calendrier->jourDe(ValeurRequise::de($derniere->created_at, 'la date de la dernière relance')), $calendrier->aujourdhui());
    }

    /**
     * Le rappel d'une relance (voir EnvoyerDocumentAction::relancer()),
     * calculé au moment où elle part (voir EnvoyerDocumentJob) : ce qu'il
     * reste à payer tient compte des règlements reçus entre-temps.
     */
    public function messageDeRelance(): string
    {
        $calculateur = app(CalculateurLigne::class);
        [$document, $accord] = $this->estPourUnProfessionnel() ? ['la facture', 'e'] : ['le ticket de caisse', ''];

        return "Sauf erreur de notre part, {$document} {$this->numero} d'un montant de {$calculateur->enEuros($calculateur->enCentiemes($this->total_ttc))} TTC, "
            ."arrivé{$accord} à échéance le ".Calendrier::afficherJour(ValeurRequise::de($this->date_echeance, 'l\'échéance de la facture en retard'), 'd/m/Y').", n'est pas entièrement réglé{$accord} : "
            ."il reste {$calculateur->enEuros($this->montantRestantEnCentimes())} à payer.\n"
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

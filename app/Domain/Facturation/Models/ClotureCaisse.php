<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\InstantFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Models\Magasin;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ClotureCaisseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use LogicException;

/**
 * La clôture d'une journée de caisse, son ticket Z (voir
 * CloturerCaisseAction). Ses totaux (total_ht, total_tva, total_ttc) sont
 * le net des tickets de caisse (ventes à des particuliers) de la journée
 * moins les avoirs sur des tickets émis ce jour-là, base de l'e-reporting
 * -- jamais les factures à des professionnels ni leurs avoirs (voir
 * Facture::scopeTickets()). Son récapitulatif garde en plus, en centimes,
 * les ventes et les avoirs séparés, les factures aux professionnels pour
 * information, les encaissements par mode, le crédit d'avoir imputé et les
 * grands totaux perpétuels (voir RecapitulatifDeCloture). Une clôture par
 * jour du calendrier à partir de la première vente, dans l'ordre (voir
 * CloturerLesJourneesAction).
 *
 * Écrite une seule fois : numérotée en base, elle ne se modifie plus (sauf
 * la soumission d'e-reporting qui la reprend) et ne se supprime jamais
 * (voir ProtegeLesDonneesValidees). PAS de SoftDeletes ici, volontairement,
 * pour un document fiscal au même titre qu'une facture, dont la loi impose
 * la conservation.
 */
class ClotureCaisse extends Model
{
    use FigeLeNomDeSesAuteurs, ProtegeLesDonneesValidees;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'cree_par' => 'cree_par_nom',
        ];
    }

    /** @use HasFactory<ClotureCaisseFactory> */
    use BelongsToMagasin, HasFactory, VentileLaTvaParTaux;

    protected $table = 'clotures_caisse';

    // numero, totaux, récapitulatif, cloturee_a : jamais en mass
    // assignment, uniquement via CloturerCaisseAction.
    protected $fillable = [
        'date_cloture',
    ];

    protected $casts = [
        'date_cloture' => 'date',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'recapitulatif' => 'array',
        'derniere_sequence_vente' => 'integer',
        'derniere_sequence_encaissement' => 'integer',
        'cloturee_a' => 'datetime',
    ];

    /**
     * Numérotée en base : la clôture est faite.
     */
    public function estValidee(): bool
    {
        return $this->exists && $this->documentNumeroteEnBase(self::class, [$this->getKey()]);
    }

    protected function regleDInalterabilite(): string
    {
        return 'Une clôture de caisse ne se modifie plus : son récapitulatif et ses totaux s\'écrivent une seule fois, à la clôture.';
    }

    /**
     * La soumission d'e-reporting qui reprend la clôture la note sur elle
     * (voir GenererSoumissionEReportingAction).
     *
     * @return list<string>
     */
    protected function colonnesModifiablesUneFoisValidee(): array
    {
        return ['soumission_ereporting_id', 'updated_at'];
    }

    /**
     * Le montant encaissé de la journée, tous modes confondus,
     * contre-passations déduites, en centimes (voir
     * RecapitulatifDeCloture).
     */
    public function totalEncaisseEnCentimes(): int
    {
        return (int) array_sum(array_column($this->recapitulatif['encaissements'], 'montant'));
    }

    /**
     * La clôture de tel jour (AAAA-MM-JJ). whereDate() plutôt qu'une
     * égalité : le cast « date » écrit la colonne « AAAA-MM-JJ 00:00:00 »
     * sous SQLite, qu'une comparaison avec « AAAA-MM-JJ » seul ne
     * trouverait jamais.
     */
    public function scopeDuJour(Builder $query, string $jour): Builder
    {
        return $query->whereDate('date_cloture', $jour);
    }

    /**
     * La dernière journée clôturée du magasin actif (AAAA-MM-JJ), null
     * tant qu'aucune ne l'est, lue sans verrou : pour l'affichage et pour
     * lister les journées à clôturer. Une émission ou une clôture la relit
     * sous le verrou du registre fiscal (voir
     * RegistreFiscal::derniereJourneeCloturee()).
     */
    public static function derniereJourneeCloturee(): ?string
    {
        $derniere = static::query()->max('date_cloture');

        return $derniere === null ? null : CarbonImmutable::parse($derniere)->toDateString();
    }

    /**
     * Une journée clôturée est finie pour la caisse : jusqu'à minuit,
     * heure de Paris, elle n'émet plus aucun ticket ni aucun avoir sur un
     * ticket (voir EmettreFactureAction et EmettreAvoirAction). Chaque
     * clôture couvre ainsi exactement sa journée, et la fenêtre de
     * confirmation du bouton de clôture le dit avant (voir
     * ListClotureCaisses). Null tant que la journée est ouverte.
     *
     * @param  CarbonInterface  $instant  l'instant de la vente
     * @param  string|null  $derniereJourneeCloturee  AAAA-MM-JJ : les journées se clôturent dans l'ordre, celle de la vente l'est donc si elle ne vient pas après
     */
    public static function refusDUneVente(CarbonInterface $instant, ?string $derniereJourneeCloturee): ?string
    {
        $journee = CarbonImmutable::instance($instant)->setTimezone(config('app.timezone'));

        return $derniereJourneeCloturee !== null && $journee->toDateString() <= $derniereJourneeCloturee
            ? "La journée du {$journee->format('d/m/Y')} est clôturée : les ventes reprennent demain."
            : null;
    }

    /**
     * Le refus du domaine, pris sous le verrou du registre fiscal que tient
     * déjà la transaction de l'émission : il lit la dernière journée
     * clôturée sur la ligne de verrou (voir
     * RegistreFiscal::derniereJourneeCloturee()), jamais dans cette table
     * sans verrou. Un ticket émis pendant la clôture de sa journée attend
     * qu'elle soit terminée, puis se voit refusé : la ligne de verrou,
     * relue après l'attente, porte la journée qu'elle vient de clôturer.
     *
     * @param  InstantFiscal  $instant  l'instant de l'émission, que donne le registre fiscal
     *
     * @throws LogicException la journée est clôturée (voir refusDUneVente())
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public static function refuserUneVenteSiLaJourneeEstCloturee(Magasin $magasin, InstantFiscal $instant): void
    {
        $refus = static::refusDUneVente($instant->utc, app(RegistreFiscal::class)->derniereJourneeCloturee($magasin));

        if ($refus !== null) {
            throw new LogicException($refus);
        }
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

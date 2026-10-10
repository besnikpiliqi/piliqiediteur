<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
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
 * Agrège les ventes à des clients particuliers d'une journée, moins les
 * avoirs qui leur ont été émis ce jour-là, pour l'e-reporting (voir
 * CloturerCaisseAction) -- jamais les ventes à des clients
 * professionnels, qui relèvent de l'e-invoicing individuelle transmise
 * facture par facture (voir Facture::relevantDeLeInvoicing). Une clôture
 * par jour du calendrier à partir de la première vente, dans l'ordre (voir
 * CloturerLesJourneesAction).
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
     * tant qu'aucune ne l'est.
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
     */
    public static function refusDUneVente(CarbonInterface $instant): ?string
    {
        $journee = CarbonImmutable::instance($instant)->setTimezone(config('app.timezone'));

        return static::query()->duJour($journee->toDateString())->exists()
            ? "La journée du {$journee->format('d/m/Y')} est clôturée : les ventes reprennent demain."
            : null;
    }

    /**
     * @param  CarbonInterface  $instant  l'instant de l'émission, que donne le registre fiscal
     *
     * @throws LogicException la journée est clôturée (voir refusDUneVente())
     */
    public static function refuserUneVenteSiLaJourneeEstCloturee(CarbonInterface $instant): void
    {
        $refus = static::refusDUneVente($instant);

        if ($refus !== null) {
            throw new LogicException($refus);
        }
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

<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\InstantFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Calendrier\Casts\JourCivil;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\ValeursLues;
use Database\Factories\ClotureCaisseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use LogicException;
use UnexpectedValueException;

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
 * CloturerLesJourneesAction). Ce que l'e-reporting déclarera pour sa
 * journée se lit sur elle (voir EReportingDesParticuliers).
 *
 * Écrite une seule fois, déjà numérotée, par la clôture de sa journée,
 * qui en signe tout le contenu au journal des événements (événement 50) :
 * elle ne se modifie plus et ne se supprime jamais, ni par le modèle (voir
 * ProtegeLesDonneesValidees) ni par une requête directe (table en ajout
 * seul, et la base refuse un ticket Z sans sa clôture signée, voir
 * GardesDesClotures). La dernière journée close, la clôture précédente et
 * ce que compte une clôture se lisent au registre signé, jamais dans
 * cette table (voir RegistreFiscal::derniereClotureSignee()), et la
 * vérification de l'intégrité compare chaque ticket Z à sa clôture signée
 * (voir VerificateurIntegrite). PAS de SoftDeletes ici, volontairement,
 * pour un document fiscal au même titre qu'une facture, dont la loi
 * impose la conservation.
 *
 * Sa journée (voir JourCivil) n'est jamais nulle : sa colonne ne l'est pas
 * en base.
 *
 * @property string $date_cloture
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
        'date_cloture' => JourCivil::class,
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'recapitulatif' => 'array',
        'derniere_sequence_vente' => 'integer',
        'derniere_sequence_encaissement' => 'integer',
        'cloturee_a' => Instant::class,
    ];

    /**
     * Enregistrée : la clôture est faite, son ticket Z s'écrit en une seule
     * fois, déjà numéroté.
     */
    public function estValidee(): bool
    {
        return $this->exists;
    }

    protected function regleDInalterabilite(): string
    {
        return 'Une clôture de caisse ne se modifie plus : son récapitulatif et ses totaux s\'écrivent une seule fois, à la clôture.';
    }

    /**
     * Le montant encaissé de la journée, tous modes confondus,
     * contre-passations déduites, en centimes (voir
     * RecapitulatifDeCloture).
     */
    public function totalEncaisseEnCentimes(): int
    {
        return array_sum(array_column($this->encaissementsParMode(), 'montant'));
    }

    /**
     * Les encaissements de la journée par mode, tels que le récapitulatif
     * les a écrits à la clôture (voir RecapitulatifDeCloture), en centimes,
     * dans leur ordre.
     *
     * @return list<array{mode: string, libelle: string, paiements: int, contre_passations: int, montant: int}>
     *
     * @throws UnexpectedValueException un mode absent, ou d'un autre type
     */
    public function encaissementsParMode(): array
    {
        return array_map(function (mixed $encaissement): array {
            $encaissement = ValeursLues::tableau($encaissement);

            return [
                'mode' => ValeursLues::texteDe($encaissement, 'mode'),
                'libelle' => ValeursLues::texteDe($encaissement, 'libelle'),
                'paiements' => ValeursLues::entierDe($encaissement, 'paiements'),
                'contre_passations' => ValeursLues::entierDe($encaissement, 'contre_passations'),
                'montant' => ValeursLues::entierDe($encaissement, 'montant'),
            ];
        }, array_values(ValeursLues::tableauDe($this->recapitulatif, 'encaissements')));
    }

    /**
     * Le crédit d'avoir imputé de la journée, en centimes, tel que le
     * récapitulatif l'a écrit.
     *
     * @throws UnexpectedValueException un crédit absent, ou qui n'est pas un entier
     */
    public function creditAvoirImpute(): int
    {
        return ValeursLues::entierDe($this->recapitulatif, 'credit_avoir_impute');
    }

    /**
     * Les grands totaux perpétuels du magasin après le dernier document de
     * la journée, en centimes, tels que le récapitulatif les a écrits.
     *
     * @return array{perpetuel_reel: int, perpetuel_absolu: int}
     *
     * @throws UnexpectedValueException des grands totaux absents, ou qui ne sont pas des entiers
     */
    public function grandsTotaux(): array
    {
        $grandsTotaux = ValeursLues::tableauDe($this->recapitulatif, 'grands_totaux');

        return [
            'perpetuel_reel' => ValeursLues::entierDe($grandsTotaux, 'perpetuel_reel'),
            'perpetuel_absolu' => ValeursLues::entierDe($grandsTotaux, 'perpetuel_absolu'),
        ];
    }

    /**
     * Les lignes de la rubrique « Ventes et avoirs », lues dans le
     * récapitulatif tel qu'il a été écrit à la clôture, en centimes :
     * chaque rubrique avec son nombre et ses montants, puis le détail de
     * chacun de ses taux ; le net des ventes aux particuliers après leurs
     * avoirs, que gardent les totaux de la clôture (la fiche de la clôture
     * et son ticket Z les montrent, voir ClotureCaisseResource et
     * ClotureCaissePdfGenerator).
     *
     * @return list<array{rubrique: string, nombre: int|null, ht: int, tva: int, ttc: int, detail: bool}>
     */
    public function rubriques(): array
    {
        $calculateur = app(CalculateurLigne::class);
        $ligne = fn (string $rubrique, ?int $nombre, int $ht, int $tva, int $ttc, bool $detail = false): array => [
            'rubrique' => $rubrique,
            'nombre' => $nombre,
            'ht' => $ht,
            'tva' => $tva,
            'ttc' => $ttc,
            'detail' => $detail,
        ];
        $rubrique = function (string $cle, string $libelle) use ($ligne): array {
            $rubrique = $this->rubriqueDuRecapitulatif($cle);

            return [
                $ligne($libelle, $rubrique['nombre'], $rubrique['ht'], $rubrique['tva'], $rubrique['ttc']),
                ...array_map(
                    fn (array $taux): array => $ligne('dont TVA à '.self::tauxEnTexte($taux['taux']), null, $taux['ht'], $taux['tva'], $taux['ttc'], true),
                    $rubrique['par_taux'],
                ),
            ];
        };

        return [
            ...$rubrique('ventes_particuliers', 'Ventes aux particuliers (tickets)'),
            ...$rubrique('avoirs_particuliers', 'Avoirs sur des tickets'),
            $ligne('Net des ventes aux particuliers (base de l\'e-reporting)', null, $calculateur->enCentiemes($this->total_ht), $calculateur->enCentiemes($this->total_tva), $calculateur->enCentiemes($this->total_ttc)),
            ...$rubrique('factures_professionnels', 'Factures aux professionnels (pour information)'),
            ...$rubrique('avoirs_professionnels', 'Avoirs aux professionnels (pour information)'),
        ];
    }

    /**
     * Le net des ventes aux particuliers taux par taux : les ventes de
     * chaque taux et leurs avoirs (écrits en négatif), additionnés en
     * centimes tels que le récapitulatif les a écrits, du taux le plus fort
     * au plus faible ; un taux dont le net est nul en tout reste (ses
     * ventes et ses avoirs se compensent).
     *
     * @return list<array{taux: int, ht: int, tva: int, ttc: int}>
     */
    public function netParTaux(): array
    {
        $nets = [];

        foreach ([...$this->rubriqueDuRecapitulatif('ventes_particuliers')['par_taux'], ...$this->rubriqueDuRecapitulatif('avoirs_particuliers')['par_taux']] as $taux) {
            $net = $nets[$taux['taux']] ?? ['taux' => $taux['taux'], 'ht' => 0, 'tva' => 0, 'ttc' => 0];
            $nets[$taux['taux']] = ['taux' => $taux['taux'], 'ht' => $net['ht'] + $taux['ht'], 'tva' => $net['tva'] + $taux['tva'], 'ttc' => $net['ttc'] + $taux['ttc']];
        }

        krsort($nets);

        return array_values($nets);
    }

    /**
     * Une rubrique du récapitulatif tel qu'il a été écrit à la clôture
     * (voir RecapitulatifDeCloture) : son nombre de documents, ses
     * montants et ceux de chacun de ses taux, en centimes, dans leur ordre.
     *
     * @return array{nombre: int, ht: int, tva: int, ttc: int, par_taux: list<array{taux: int, ht: int, tva: int, ttc: int}>}
     *
     * @throws UnexpectedValueException une rubrique absente, ou un montant qui n'est pas un entier
     */
    private function rubriqueDuRecapitulatif(string $cle): array
    {
        $rubrique = ValeursLues::tableauDe($this->recapitulatif, $cle);

        return [
            'nombre' => ValeursLues::entierDe($rubrique, 'nombre'),
            'ht' => ValeursLues::entierDe($rubrique, 'ht'),
            'tva' => ValeursLues::entierDe($rubrique, 'tva'),
            'ttc' => ValeursLues::entierDe($rubrique, 'ttc'),
            'par_taux' => array_map(function (mixed $taux): array {
                $taux = ValeursLues::tableau($taux);

                return [
                    'taux' => ValeursLues::entierDe($taux, 'taux'),
                    'ht' => ValeursLues::entierDe($taux, 'ht'),
                    'tva' => ValeursLues::entierDe($taux, 'tva'),
                    'ttc' => ValeursLues::entierDe($taux, 'ttc'),
                ];
            }, array_values(ValeursLues::tableauDe($rubrique, 'par_taux'))),
        ];
    }

    /**
     * Un taux en centièmes de point tel qu'il s'écrit : 2000 donne
     * « 20 % », 550 « 5,5 % ».
     */
    public static function tauxEnTexte(int $centiemes): string
    {
        return rtrim(rtrim(sprintf('%d,%02d', intdiv($centiemes, 100), $centiemes % 100), '0'), ',').' %';
    }

    /**
     * La clôture de tel jour du calendrier (AAAA-MM-JJ).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDuJour(Builder $query, string $jour): Builder
    {
        return $query->where('date_cloture', $jour);
    }

    /**
     * Une journée clôturée est finie pour la caisse : jusqu'au minuit du
     * calendrier de son magasin, elle n'émet plus aucun ticket ni aucun
     * avoir sur un ticket (voir EmettreFactureAction et EmettreAvoirAction).
     * Chaque clôture couvre ainsi exactement sa journée, et la fenêtre de
     * confirmation du bouton de clôture le dit avant (voir
     * ListClotureCaisses). Null tant que la journée est ouverte.
     *
     * @param  string  $journee  AAAA-MM-JJ : la journée de la vente, dans le calendrier de son magasin
     * @param  string|null  $derniereJourneeCloturee  AAAA-MM-JJ : les journées se clôturent dans l'ordre, celle de la vente l'est donc si elle ne vient pas après
     */
    public static function refusDUneVente(string $journee, ?string $derniereJourneeCloturee): ?string
    {
        return $derniereJourneeCloturee !== null && $journee <= $derniereJourneeCloturee
            ? 'La journée du '.Calendrier::afficherJour($journee, 'd/m/Y').' est clôturée : les ventes reprennent demain.'
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
        $refus = static::refusDUneVente($instant->jour(), app(RegistreFiscal::class)->derniereJourneeCloturee($magasin));

        if ($refus !== null) {
            throw new LogicException($refus);
        }
    }

    /**
     * @return HasMany<Facture, $this>
     */
    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    /**
     * Les avoirs à des particuliers émis ce jour-là, déduits des ventes
     * (voir CloturerCaisseAction).
     *
     * @return HasMany<Avoir, $this>
     */
    public function avoirs(): HasMany
    {
        return $this->hasMany(Avoir::class);
    }

    /**
     * Les lignes de toutes les ventes de la journée, pour la ventilation
     * par taux de TVA (voir VentileLaTvaParTaux).
     *
     * @return HasManyThrough<LigneFacture, Facture, $this>
     */
    public function lignes(): HasManyThrough
    {
        return $this->hasManyThrough(LigneFacture::class, Facture::class);
    }

    /**
     * @return HasManyThrough<LigneAvoir, Avoir, $this>
     */
    public function lignesAvoir(): HasManyThrough
    {
        return $this->hasManyThrough(LigneAvoir::class, Avoir::class);
    }

    /**
     * @return Collection<int, array{taux_tva: string, montant_ht: string, montant_tva: string, montant_ttc: string}>
     */
    protected function lignesAVentiler(): Collection
    {
        return self::montantsDesLignes($this->lignes)->concat(self::montantsDeduits($this->lignesAvoir));
    }
}

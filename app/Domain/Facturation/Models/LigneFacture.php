<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La ligne d'une facture ou d'un ticket : modifiable, supprimable et
 * ajoutable tant que sa facture est un brouillon, figée dès qu'elle est
 * émise (voir ProtegeLesDonneesValidees).
 */
class LigneFacture extends Model
{
    use BelongsToMagasin, ProtegeLesDonneesValidees;

    protected $table = 'lignes_facture';

    protected $fillable = [
        'facture_id',
        'produit_id',
        'service_id',
        'nature',
        'description',
        'reference',
        'quantite',
        'prix_unitaire_ht',
        'prix_unitaire_ttc',
        'taux_tva',
        'taux_tva_du_prix',
        'remise_pourcentage',
        'montant_ht',
        'montant_tva',
        'montant_ttc',
        'ordre',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire_ht' => 'decimal:6',
        'prix_unitaire_ttc' => 'decimal:2',
        'nature' => NatureLigne::class,
        'taux_tva' => 'decimal:2',
        'taux_tva_du_prix' => 'decimal:2',
        'remise_pourcentage' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    /**
     * Sa facture, avant comme après la modification, est numérotée en
     * base : la ligne appartient à un document émis.
     */
    public function estValidee(): bool
    {
        return $this->documentNumeroteEnBase(Facture::class, [ValeurSimple::enEntierOuNul($this->getRawOriginal('facture_id')), ValeurSimple::enEntierOuNul($this->getAttribute('facture_id'))]);
    }

    protected function regleDInalterabilite(): string
    {
        return 'Les lignes d\'une facture ou d\'un ticket émis ne se modifient plus : une correction passe par un avoir.';
    }

    /**
     * @return BelongsTo<Facture, $this>
     */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    /**
     * Une ligne garde son produit, même archivé depuis (voir les
     * SoftDeletes de Produit) : la vente émise ensuite le sort quand même
     * du stock (voir DecrementerStockApresEmissionFacture).
     *
     * @return BelongsTo<Produit, $this>
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }

    /**
     * Le service du catalogue dont vient la ligne (voir Service), jamais en
     * même temps qu'un produit.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<LigneAvoir, $this>
     */
    public function lignesAvoir(): HasMany
    {
        return $this->hasMany(LigneAvoir::class);
    }

    /**
     * Quantité déjà créditée par des avoirs ÉMIS référençant cette ligne.
     * Un avoir encore brouillon n'a aucune existence légale (voir
     * Avoir::class) : il ne doit jamais réduire la quantité restante
     * créditable, au même titre qu'il ne bloque pas un nouvel avoir (voir
     * CreerAvoirAction).
     */
    public function quantiteDejaCreditee(): int
    {
        return (int) $this->lignesAvoir()
            ->whereHas('avoir', fn ($query) => $query->where('statut', StatutAvoir::Emise))
            ->sum('quantite');
    }

    /**
     * Permet un avoir partiel, et plusieurs avoirs successifs sur une même
     * ligne, tant que leur somme ne dépasse jamais la quantité facturée
     * (voir CreerAvoirAction).
     */
    public function quantiteCreditableRestante(): int
    {
        return $this->quantite - $this->quantiteDejaCreditee();
    }

    /**
     * Les lignes qu'il reste à créditer, comme quantiteCreditableRestante()
     * mais en une seule requête : celles qu'aucun avoir émis n'a encore
     * entièrement créditées. Les lignes d'avoir se lisent dans le magasin
     * de chaque ligne (voir BelongsToMagasin::scopeDuMagasinDe()) : la
     * condition vaut dans le magasin actif comme pour plusieurs magasins
     * de la société (voir ChosesAFaireDeLaSociete).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCreditables(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('quantite'), '>', LigneAvoir::query()
            ->duMagasinDe($this->qualifyColumn('magasin_id'))
            ->selectRaw('coalesce(sum(lignes_avoir.quantite), 0)')
            ->whereColumn('lignes_avoir.ligne_facture_id', $this->getQualifiedKeyName())
            ->whereHas('avoir', fn (Builder $avoirs): Builder => $avoirs->duMagasinDe('lignes_avoir.magasin_id')->where('statut', StatutAvoir::Emise)));
    }

    /**
     * Les lignes de biens qui portent de la TVA : celles dont une détaxe
     * rembourse la TVA au voyageur (voir NoterBordereauDetaxeAction et
     * BordereauDetaxe::avertissement()), jamais un service ni un bien
     * vendu sans TVA.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBiensTaxes(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('nature'), NatureLigne::Bien)->where($this->qualifyColumn('montant_tva'), '>', 0);
    }

    /**
     * Les montants des lignes d'un avoir, dans leur ordre, écrits comme
     * leurs colonnes : pour chaque ligne vendue, le retour de $quantite
     * articles, calculé avec les autres lignes de l'avoir (voir
     * CalculateurLigne::chiffrerUnAvoir()) sur tout ce qui en a été rendu
     * jusqu'ici, sans dépasser le HT ni la TVA de la ligne, moins ce que
     * les avoirs déjà émis ont crédité. Le dernier retour d'une ligne
     * retombe ainsi exactement sur la ligne vendue.
     *
     * @param  list<array{0: self, 1: int}>  $retours  chaque ligne vendue et le nombre d'articles que l'avoir en rend
     * @return list<array{montant_ht: string, montant_tva: string, montant_ttc: string}>
     */
    public static function montantsDesRetours(array $retours): array
    {
        $calculateur = app(CalculateurLigne::class);

        return array_map(
            fn (array $montants): array => array_map($calculateur->enDecimal(...), $montants),
            $calculateur->chiffrerUnAvoir(array_map(fn (array $retour): array => $retour[0]->retourAChiffrer($retour[1]), $retours))['lignes'],
        );
    }

    /**
     * Un retour de $quantite articles de cette ligne, tel que le calcul
     * d'un avoir le lit (voir CalculateurLigne::chiffrerUnAvoir()) : la
     * ligne vendue, avec son PU HT en millionièmes d'euro, tous ses
     * articles rendus par les avoirs émis, ce retour compris, et ce que ces
     * avoirs ont rendu (articles, HT et TVA), en centimes et en centièmes
     * de point.
     *
     * @return array{ligne: array{prix_unitaire_ttc: int, prix_unitaire_ht: int, quantite: int, remise_pourcentage: int, taux_tva_du_prix: int, taux_tva: int, montant_ht: int, montant_tva: int}, quantite_rendue: int, deja_rendu: array{quantite: int, montant_ht: int, montant_tva: int}}
     */
    private function retourAChiffrer(int $quantite): array
    {
        $calculateur = app(CalculateurLigne::class);
        $avoirsEmis = $this->lignesAvoir()
            ->whereHas('avoir', fn ($query) => $query->where('statut', StatutAvoir::Emise))
            ->get(['quantite', 'montant_ht', 'montant_tva']);
        $centiemes = fn (string $colonne): int => $calculateur->enCentiemes($this->getAttribute($colonne));
        $dejaRendus = ValeursLues::entier($avoirsEmis->sum('quantite'));

        return [
            'ligne' => [
                'prix_unitaire_ttc' => $centiemes('prix_unitaire_ttc'),
                'prix_unitaire_ht' => $calculateur->enMillioniemes($this->prix_unitaire_ht),
                'quantite' => $this->quantite,
                'remise_pourcentage' => $centiemes('remise_pourcentage'),
                'taux_tva_du_prix' => $centiemes('taux_tva_du_prix'),
                'taux_tva' => $centiemes('taux_tva'),
                'montant_ht' => $centiemes('montant_ht'),
                'montant_tva' => $centiemes('montant_tva'),
            ],
            'quantite_rendue' => $dejaRendus + $quantite,
            'deja_rendu' => [
                'quantite' => $dejaRendus,
                'montant_ht' => $avoirsEmis->sum(fn (LigneAvoir $ligne): int => $calculateur->enCentiemes($ligne->montant_ht)),
                'montant_tva' => $avoirsEmis->sum(fn (LigneAvoir $ligne): int => $calculateur->enCentiemes($ligne->montant_tva)),
            ],
        ];
    }
}

<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
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
        'prix_unitaire_ht' => 'decimal:2',
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
        return $this->documentNumeroteEnBase(Facture::class, [$this->getRawOriginal('facture_id'), $this->getAttribute('facture_id')]);
    }

    protected function regleDInalterabilite(): string
    {
        return 'Les lignes d\'une facture ou d\'un ticket émis ne se modifient plus : une correction passe par un avoir.';
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    /**
     * Une ligne garde son produit, même archivé depuis (voir les
     * SoftDeletes de Produit) : la vente émise ensuite le sort quand même
     * du stock (voir DecrementerStockApresEmissionFacture).
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }

    /**
     * Le service du catalogue dont vient la ligne (voir Service), jamais en
     * même temps qu'un produit.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

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
     * entièrement créditées.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCreditables(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('quantite'), '>', LigneAvoir::query()
            ->selectRaw('coalesce(sum(lignes_avoir.quantite), 0)')
            ->whereColumn('lignes_avoir.ligne_facture_id', $this->getQualifiedKeyName())
            ->whereHas('avoir', fn (Builder $avoirs): Builder => $avoirs->where('statut', StatutAvoir::Emise)));
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
     * Montants d'un retour de $quantite articles de cette ligne : la ligne
     * calculée pour tout ce qui en a été rendu jusqu'ici, moins ce que les
     * avoirs déjà émis ont crédité (voir CalculateurLigne::retourCumule()).
     * Le cumul se calcule comme la facture (voir
     * ContexteDeVente::documentCalculeDepuisLeHtDeLigne()) : depuis le HT
     * de la ligne sur une facture à un professionnel (E1, K2, voir
     * CalculateurLigne::retourDeLigneProfessionnelle()), article par
     * article sur un ticket ou sur une facture émise en franchise en base,
     * à son PU TTC payé (voir prixUnitaireTtcPaye()). Le dernier retour
     * retombe ainsi exactement sur la ligne vendue.
     *
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function montantsDuRetour(int $quantite): array
    {
        $calculateur = app(CalculateurLigne::class);
        $avoirsEmis = $this->lignesAvoir()
            ->whereHas('avoir', fn ($query) => $query->where('statut', StatutAvoir::Emise))
            ->get(['quantite', 'montant_ht', 'montant_tva', 'montant_ttc']);
        $quantiteRendue = (int) $avoirsEmis->sum('quantite') + $quantite;

        return $calculateur->retourCumule(
            ContexteDeVente::documentCalculeDepuisLeHtDeLigne($this->facture)
                ? $calculateur->retourDeLigneProfessionnelle($this->only(['prix_unitaire_ttc', 'taux_tva', 'taux_tva_du_prix', 'quantite', 'remise_pourcentage', 'montant_ht', 'montant_tva']), $quantiteRendue)
                : $calculateur->montantsDeVente((float) $this->prix_unitaire_ht, $this->prixUnitaireTtcPaye(), $quantiteRendue, (float) $this->remise_pourcentage),
            $avoirsEmis->toArray(),
        );
    }

    /**
     * Le PU TTC qu'a payé le client : son PU TTC, sauf quand la TVA qu'il
     * comprend a été retirée du prix (vente hors de la TVA française, voir
     * ConstructeurLigneDocument) : la ligne est alors à 0 %, et le client a
     * payé son PU HT.
     */
    private function prixUnitaireTtcPaye(): float
    {
        $calculateur = app(CalculateurLigne::class);

        return (float) ($calculateur->enCentiemes($this->taux_tva_du_prix) === $calculateur->enCentiemes($this->taux_tva)
            ? $this->prix_unitaire_ttc
            : $this->prix_unitaire_ht);
    }
}

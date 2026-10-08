<?php

namespace App\Domain\Achats\Models;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LigneAchat extends Model
{
    use BelongsToMagasin;

    protected $table = 'lignes_achat';

    protected $fillable = [
        'facture_achat_id',
        'produit_id',
        'description',
        'reference',
        'quantite',
        'prix_unitaire_ht',
        'taux_tva',
        'remise_pourcentage',
        'montant_ht',
        'montant_tva',
        'montant_ttc',
        'ordre',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'remise_pourcentage' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    public function factureAchat(): BelongsTo
    {
        return $this->belongsTo(FactureAchat::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function lignesAvoirFournisseur(): HasMany
    {
        return $this->hasMany(LigneAvoirFournisseur::class);
    }

    /**
     * Ce qu'un article de cette ligne a réellement coûté : son PU HT après
     * la remise de la ligne, puis l'escompte de la facture. Le coût d'achat
     * d'un stock se compte après remises, rabais et escomptes (PCG art.
     * 213-31) ; c'est à ce coût que la ligne entre en stock et compte dans
     * le CMUP du produit (voir StockLedgerService::receptionner()).
     */
    public function coutUnitaireHt(): float
    {
        return app(CalculateurLigne::class)->coutUnitaireNet(
            (float) $this->prix_unitaire_ht,
            (float) $this->remise_pourcentage,
            (float) $this->factureAchat->escompte_pourcentage,
        );
    }

    /**
     * Quantité déjà retournée au fournisseur par des avoirs ÉMIS
     * référençant cette ligne. Un avoir encore brouillon n'a aucune
     * existence légale : il ne doit jamais réduire la quantité restante
     * retournable (même logique que LigneFacture::quantiteDejaCreditee()).
     */
    public function quantiteDejaRetournee(): int
    {
        return (int) $this->lignesAvoirFournisseur()
            ->whereHas('avoirFournisseur', fn ($query) => $query->where('statut', StatutAvoirFournisseur::Emise))
            ->sum('quantite');
    }

    /**
     * Un produit déjà vendu à un client ne doit jamais pouvoir être
     * retourné au fournisseur. Cette application ne trace pas le stock par
     * lot/unité (un seul compteur agrégé produits.stock_quantite, voir
     * StockLedgerService) : on ne peut donc pas savoir si CE lot précis a
     * été vendu, seulement combien d'unités du produit restent en stock au
     * total. On plafonne donc la quantité retournable au minimum entre le
     * reliquat de cette ligne et le stock actuellement disponible du
     * produit -- un produit vendu a quitté le stock, il ne peut
     * mécaniquement plus être compté dans ce qui reste disponible au
     * retour.
     */
    /**
     * Montants d'un retour de $quantite articles de cette ligne au
     * fournisseur : la ligne calculée pour tout ce qui en a été retourné
     * jusqu'ici, moins ce que les avoirs fournisseur déjà émis ont crédité
     * (voir CalculateurLigne::retourCumule()). Le dernier retour retombe
     * ainsi exactement sur la ligne achetée.
     *
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function montantsDuRetour(int $quantite): array
    {
        $calculateur = app(CalculateurLigne::class);
        $avoirsEmis = $this->lignesAvoirFournisseur()
            ->whereHas('avoirFournisseur', fn ($query) => $query->where('statut', StatutAvoirFournisseur::Emise))
            ->get(['quantite', 'montant_ht', 'montant_tva', 'montant_ttc']);

        return $calculateur->retourCumule(
            $calculateur->montantsDAchat(
                (float) $this->prix_unitaire_ht,
                (float) $this->taux_tva,
                (int) $avoirsEmis->sum('quantite') + $quantite,
                (float) $this->remise_pourcentage,
            ),
            $avoirsEmis->toArray(),
        );
    }

    public function quantiteRetournableRestante(): int
    {
        $resteSurLaLigne = $this->quantite - $this->quantiteDejaRetournee();

        // Le plafond par stock disponible n'a de sens que pour un produit
        // dont le stock est effectivement suivi (voir StockLedgerService) :
        // pour les autres, stock_quantite reste figé à 0 et ne reflète
        // rien, on se fie alors uniquement au reliquat de la ligne.
        if ($this->produit === null || ! $this->produit->suivi_stock) {
            return max(0, $resteSurLaLigne);
        }

        return max(0, min($resteSurLaLigne, $this->produit->stock_quantite));
    }
}

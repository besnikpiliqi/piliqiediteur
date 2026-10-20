<?php

namespace App\Domain\Achats\Models;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tva\Services\CalculateurLigne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Une ligne reçoit toujours un produit du catalogue (produit_id) ; seule
 * la ligne d'un brouillon peut garder à sa place la fiche d'un nouveau
 * produit (nouveau_produit), qui ne sera créé qu'à la validation de la
 * facture d'achat (voir ConstructeurLigneAchat::creerLesNouveauxProduits()) :
 * un brouillon supprimé ne laisse ainsi aucun produit derrière lui. Jamais
 * les deux à la fois, jamais aucun des deux.
 */
class LigneAchat extends Model
{
    use BelongsToMagasin;

    protected $table = 'lignes_achat';

    protected $fillable = [
        'facture_achat_id',
        'produit_id',
        'nouveau_produit',
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
        'nouveau_produit' => 'array',
        'quantite' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'remise_pourcentage' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (LigneAchat $ligne) {
            if (blank($ligne->produit_id) === blank($ligne->nouveau_produit)) {
                throw new InvalidArgumentException('Une ligne d\'achat reçoit soit un produit du catalogue, soit la fiche d\'un nouveau produit.');
            }
        });
    }

    public function factureAchat(): BelongsTo
    {
        return $this->belongsTo(FactureAchat::class);
    }

    /**
     * Une ligne garde son produit, même archivé depuis (voir les
     * SoftDeletes de Produit) : il se retourne toujours au fournisseur
     * (voir quantiteRetournableRestante()). Aucun tant que la ligne d'un
     * brouillon garde la fiche d'un nouveau produit.
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
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
     * Montants d'un retour de $quantite articles de cette ligne au
     * fournisseur, écrits comme leurs colonnes : le HT de tout ce qui en a
     * été retourné jusqu'ici et la TVA de ce HT, sans dépasser celle de la
     * ligne, moins ce que les avoirs fournisseur déjà émis ont crédité (voir
     * CalculateurLigne::retourDAchat()). Le dernier retour retombe ainsi
     * exactement sur la ligne achetée.
     *
     * @return array{montant_ht: string, montant_tva: string, montant_ttc: string}
     */
    public function montantsDuRetour(int $quantite): array
    {
        $calculateur = app(CalculateurLigne::class);
        $avoirsEmis = $this->lignesAvoirFournisseur()
            ->whereHas('avoirFournisseur', fn ($query) => $query->where('statut', StatutAvoirFournisseur::Emise))
            ->get(['quantite', 'montant_ht', 'montant_tva']);
        $centiemes = fn (string $colonne): int => $calculateur->enCentiemes($this->getAttribute($colonne));

        return array_map($calculateur->enDecimal(...), $calculateur->retourDAchat(
            [
                'prix_unitaire_ht' => $centiemes('prix_unitaire_ht'),
                'quantite' => $this->quantite,
                'remise_pourcentage' => $centiemes('remise_pourcentage'),
                'taux_tva' => $centiemes('taux_tva'),
                'montant_ht' => $centiemes('montant_ht'),
                'montant_tva' => $centiemes('montant_tva'),
            ],
            (int) $avoirsEmis->sum('quantite') + $quantite,
            [
                'montant_ht' => $avoirsEmis->sum(fn (LigneAvoirFournisseur $ligne): int => $calculateur->enCentiemes($ligne->montant_ht)),
                'montant_tva' => $avoirsEmis->sum(fn (LigneAvoirFournisseur $ligne): int => $calculateur->enCentiemes($ligne->montant_tva)),
            ],
        ));
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
     * retour. Plusieurs lignes du même produit se partagent ce stock (voir
     * AvoirFournisseur::verifierQueLeStockSuffit()).
     */
    public function quantiteRetournableRestante(): int
    {
        $resteSurLaLigne = $this->quantite - $this->quantiteDejaRetournee();

        // Le plafond par stock disponible n'a de sens que pour un produit
        // dont le stock est effectivement suivi (voir StockLedgerService) :
        // pour les autres, stock_quantite reste figé à 0 et ne reflète
        // rien, on se fie alors uniquement au reliquat de la ligne.
        if (! $this->produit->suivi_stock) {
            return max(0, $resteSurLaLigne);
        }

        return max(0, min($resteSurLaLigne, $this->produit->stock_quantite));
    }

    /**
     * Les lignes dont il reste au moins un article à retourner, la même
     * règle que quantiteRetournableRestante() lue par la base pour toute
     * une liste (choix de la facture d'achat d'un avoir fournisseur) :
     * moins d'articles retournés par des avoirs émis que de reçus, et un
     * produit, même archivé, dont le stock n'est pas suivi ou reste
     * positif. La somme porte sur des quantités entières ; les fiches
     * liées se lisent dans le magasin actif (voir MagasinScope).
     *
     * @param  Builder<LigneAchat>  $query
     * @return Builder<LigneAchat>
     */
    public function scopeRetournables(Builder $query): Builder
    {
        $dejaRetournees = LigneAvoirFournisseur::query()
            ->selectRaw('coalesce(sum(lignes_avoir_fournisseur.quantite), 0)')
            ->whereColumn('lignes_avoir_fournisseur.ligne_achat_id', 'lignes_achat.id')
            ->whereHas('avoirFournisseur', fn (Builder $avoir): Builder => $avoir->where('statut', StatutAvoirFournisseur::Emise));

        return $query
            ->where('lignes_achat.quantite', '>', $dejaRetournees)
            ->whereHas('produit', fn (Builder $produit): Builder => $produit->where(
                fn (Builder $stock): Builder => $stock->where('suivi_stock', false)->orWhere('stock_quantite', '>', 0),
            ));
    }
}

<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use Database\Factories\ProduitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Produit extends Model
{
    /** @use HasFactory<ProduitFactory> */
    use BelongsToMagasin, HasFactory, SoftDeletes;

    protected $fillable = [
        'produit_parent_id',
        'fournisseur_id',
        'reference',
        'code_barres',
        'nom',
        'taille',
        'couleur',
        'prix_achat_ht',
        'prix_vente_ht',
        'prix_vente_ttc',
        'taux_tva',
        'suivi_stock',
        'seuil_alerte',
        'actif',
    ];

    // stock_quantite n'est volontairement PAS fillable : seul
    // StockLedgerService est autorisé à la modifier, via les mouvements de
    // stock. Toute autre écriture casserait la cohérence du ledger.

    protected static function booted(): void
    {
        // saving() (pas creating()) : couvre aussi bien la création qu'une
        // édition où la référence serait vidée -- la colonne reste NOT
        // NULL en base, jamais question de la laisser vide après coup.
        static::saving(function (Produit $produit) {
            // La référence reste libre à la saisie (manuelle ou future
            // intégration API) : si elle n'est pas fournie, elle est
            // générée automatiquement, par magasin, jamais remise à zéro
            // (pas de periodKey, contrairement aux documents fiscaux qui
            // se réinitialisent chaque année) -- un catalogue produit n'a
            // pas de notion d'exercice.
            if (blank($produit->reference)) {
                $produit->reference = app(DocumentNumberingService::class)->suivant(
                    app(TenantContext::class)->magasin(),
                    TypeDocument::Produit,
                );
            }

            $produit->synchroniserPrixDeVente();
            $produit->arrondirPrixDAchat();
        });
    }

    protected $casts = [
        'prix_achat_ht' => 'decimal:2',
        'prix_vente_ht' => 'decimal:2',
        'prix_vente_ttc' => 'decimal:2',
        'taux_tva' => 'decimal:2',
        'stock_quantite' => 'integer',
        'seuil_alerte' => 'integer',
        'suivi_stock' => 'boolean',
        'actif' => 'boolean',
    ];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'produit_parent_id');
    }

    public function declinaisons(): HasMany
    {
        return $this->hasMany(self::class, 'produit_parent_id');
    }

    public function mouvementsStock(): HasMany
    {
        return $this->hasMany(MouvementStock::class);
    }

    /**
     * Le prix de vente TTC fait référence (c'est lui qu'une ligne de vente
     * reprend, voir ConstructeurLigneDocument) : le HT n'en est que la
     * conversion au centime, pour l'affichage. Quelle que soit la façon dont
     * le produit est enregistré (formulaire TTC, saisie HT d'une fabrique ou
     * d'une API, changement de taux), les deux restent toujours cohérents.
     */
    private function synchroniserPrixDeVente(): void
    {
        $calculateur = app(CalculateurLigne::class);
        $tauxTva = (float) $this->taux_tva;

        if ($this->isDirty('prix_vente_ttc') || ($this->isDirty('taux_tva') && ! $this->isDirty('prix_vente_ht'))) {
            $this->prix_vente_ttc = $calculateur->arrondirAuCentime((float) $this->prix_vente_ttc);
            $this->prix_vente_ht = $calculateur->prixUnitaireHtDepuisTtc((float) $this->prix_vente_ttc, $tauxTva);

            return;
        }

        if ($this->isDirty('prix_vente_ht')) {
            $this->prix_vente_ht = $calculateur->arrondirAuCentime((float) $this->prix_vente_ht);
            $this->prix_vente_ttc = $calculateur->prixUnitaireTtcDepuisHt((float) $this->prix_vente_ht, $tauxTva);
        }
    }

    /**
     * Le prix d'achat est enregistré au centime, comme il sera relu (cast
     * decimal:2) : jamais 8,335 € en base derrière un 8,34 € affiché.
     */
    private function arrondirPrixDAchat(): void
    {
        if ($this->isDirty('prix_achat_ht')) {
            $this->prix_achat_ht = app(CalculateurLigne::class)->arrondirAuCentime((float) $this->prix_achat_ht);
        }
    }

    public function enAlerteStock(): bool
    {
        return $this->suivi_stock && $this->stock_quantite <= $this->seuil_alerte;
    }
}

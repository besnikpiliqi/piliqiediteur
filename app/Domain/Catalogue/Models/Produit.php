<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use Database\Factories\ProduitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

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
        'cmup_ht',
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

    /**
     * Ce que le fournisseur dit du produit : figé dès que le produit est
     * reçu sur une facture d'achat récupérée automatiquement (voir
     * vientDUneFactureAchatApi()).
     */
    public const ATTRIBUTS_DU_FOURNISSEUR = [
        'reference',
        'code_barres',
        'nom',
        'taille',
        'couleur',
        'fournisseur_id',
    ];

    protected static function booted(): void
    {
        // saving() (pas creating()) : couvre aussi bien la création qu'une
        // édition où la référence serait vidée -- la colonne reste NOT
        // NULL en base, jamais question de la laisser vide après coup.
        static::saving(function (Produit $produit) {
            $produit->verifierQueSaReferenceEstLibre();

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
            $produit->arrondirLeCmup();
        });
    }

    protected $casts = [
        'cmup_ht' => 'decimal:4',
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

    public function lignesAchat(): HasMany
    {
        return $this->hasMany(LigneAchat::class);
    }

    /**
     * Un produit reçu sur une facture d'achat récupérée automatiquement
     * (voir SourceFactureAchat) garde ce que son fournisseur en dit (voir
     * ATTRIBUTS_DU_FOURNISSEUR) : une erreur ne pourrait venir que de la
     * source elle-même, jamais d'une saisie locale -- comme pour la facture
     * (voir FactureAchat::estModifiable()). Le magasin reste maître de ce
     * qui ne regarde que lui : prix de vente, TVA, suivi du stock.
     */
    public function vientDUneFactureAchatApi(): bool
    {
        return $this->exists && $this->lignesAchat()
            ->whereHas('factureAchat', fn (Builder $factureAchat) => $factureAchat->where('source', SourceFactureAchat::Api))
            ->exists();
    }

    /**
     * Le fournisseur d'un produit est celui chez qui il vient d'être acheté
     * (facture d'achat validée).
     */
    public function rattacherAuFournisseur(int $fournisseurId): void
    {
        $this->forceFill(['fournisseur_id' => $fournisseurId])->save();
    }

    /**
     * Le prix unitaire HT de son dernier achat (avant remise), pour
     * proposer le même sur une nouvelle facture d'achat ; à défaut, son
     * CMUP. Le dernier achat est celui de la facture la plus récemment
     * enregistrée : corriger une facture plus ancienne n'en fait pas le
     * dernier achat.
     */
    public function dernierPrixDAchatHt(): float
    {
        return (float) ($this->lignesAchat()->orderByDesc('facture_achat_id')->orderByDesc('ordre')->value('prix_unitaire_ht')
            ?? app(CalculateurLigne::class)->arrondirAuCentime((float) $this->cmup_ht));
    }

    /**
     * La base refuse deux produits du magasin avec la même référence
     * (archivés compris) : mieux vaut le dire clairement qu'échouer sur
     * l'index unique.
     */
    private function verifierQueSaReferenceEstLibre(): void
    {
        if (blank($this->reference) || ! $this->isDirty('reference')) {
            return;
        }

        $dejaPrise = static::withTrashed()
            ->where('reference', $this->reference)
            ->when($this->exists, fn (Builder $produits) => $produits->whereKeyNot($this->getKey()))
            ->exists();

        if ($dejaPrise) {
            throw new InvalidArgumentException("La référence « {$this->reference} » est déjà celle d'un autre produit.");
        }
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
     * Le CMUP est enregistré au dix-millième d'euro, comme il sera relu
     * (cast decimal:4).
     */
    private function arrondirLeCmup(): void
    {
        if ($this->isDirty('cmup_ht')) {
            $this->cmup_ht = app(CalculateurLigne::class)->arrondirAuDixMillieme((float) $this->cmup_ht);
        }
    }

    public function enAlerteStock(): bool
    {
        return $this->suivi_stock && $this->stock_quantite <= $this->seuil_alerte;
    }
}

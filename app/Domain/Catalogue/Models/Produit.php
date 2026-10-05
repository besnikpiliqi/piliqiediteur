<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\TypeDocument;
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
        });
    }

    protected $casts = [
        // 4 décimales, pas 2 : voir le docblock de CalculateurLigne.
        'prix_achat_ht' => 'decimal:4',
        'prix_vente_ht' => 'decimal:4',
        'taux_tva' => 'decimal:2',
        'stock_quantite' => 'decimal:3',
        'seuil_alerte' => 'decimal:3',
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

    public function prixVenteTtc(): float
    {
        return round((float) $this->prix_vente_ht * (1 + (float) $this->taux_tva / 100), 2);
    }

    public function enAlerteStock(): bool
    {
        return $this->suivi_stock && $this->stock_quantite <= $this->seuil_alerte;
    }
}

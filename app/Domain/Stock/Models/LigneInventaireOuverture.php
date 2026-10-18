<?php

namespace App\Domain\Stock\Models;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * Une ligne fait entrer un produit en stock : un produit du magasin
 * jamais reçu (produit_id), ou un nouveau produit, dont la ligne d'un
 * brouillon garde seulement la fiche (nouveau_produit) jusqu'à la
 * validation de l'inventaire, qui le crée (voir
 * ValiderInventaireOuvertureAction). Jamais les deux à la fois, jamais
 * aucun des deux. Une ligne d'un inventaire validé ne change plus.
 */
class LigneInventaireOuverture extends Model
{
    use BelongsToMagasin;

    protected $table = 'lignes_inventaire_ouverture';

    protected $fillable = [
        'inventaire_ouverture_id',
        'produit_id',
        'nouveau_produit',
        'description',
        'reference',
        'quantite',
        'cout_unitaire_ht',
        'valeur_ht',
        'ordre',
    ];

    protected $casts = [
        'nouveau_produit' => 'array',
        'quantite' => 'integer',
        'cout_unitaire_ht' => 'decimal:2',
        'valeur_ht' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (LigneInventaireOuverture $ligne): void {
            if (blank($ligne->produit_id) === blank($ligne->nouveau_produit)) {
                throw new InvalidArgumentException('Une ligne d\'inventaire d\'ouverture reçoit soit un produit jamais reçu, soit la fiche d\'un nouveau produit.');
            }

            if ($ligne->quantite < 1) {
                throw new InvalidArgumentException('La quantité doit être un nombre entier d\'articles, au moins 1.');
            }

            if ((float) $ligne->cout_unitaire_ht < 0) {
                throw new InvalidArgumentException('Le coût unitaire d\'un produit ne peut pas être négatif.');
            }

            $ligne->verifierQueSonInventaireEstUnBrouillon();
        });

        static::deleting(function (LigneInventaireOuverture $ligne): void {
            $ligne->verifierQueSonInventaireEstUnBrouillon();
        });
    }

    public function inventaireOuverture(): BelongsTo
    {
        return $this->belongsTo(InventaireOuverture::class);
    }

    /**
     * Le produit que la ligne fait entrer en stock, même archivé depuis ;
     * aucun tant que la ligne d'un brouillon garde la fiche d'un nouveau
     * produit.
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }

    /**
     * Relu en base : une ligne d'un inventaire validé ne s'écrit ni ne
     * s'efface plus.
     *
     * @throws LogicException
     */
    private function verifierQueSonInventaireEstUnBrouillon(): void
    {
        if (InventaireOuverture::query()->whereKey($this->inventaire_ouverture_id)->value('statut') === StatutInventaireOuverture::Valide) {
            throw new LogicException(InventaireOuverture::REFUS_VALIDE);
        }
    }
}

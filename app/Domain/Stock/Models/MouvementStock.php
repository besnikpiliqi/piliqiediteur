<?php

namespace App\Domain\Stock\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Ledger append-only. Ne jamais update() une ligne existante : pour
 * corriger un mouvement, on enregistre un mouvement inverse (voir
 * StockLedgerService::ajuster()). C'est ce qui garantit que
 * produits.stock_quantite est toujours reconstructible et auditable.
 */
class MouvementStock extends Model
{
    use BelongsToMagasin, FigeLeNomDeSesAuteurs;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'cree_par' => 'cree_par_nom',
        ];
    }

    protected $table = 'mouvements_stock';

    public const UPDATED_AT = null;

    protected $fillable = [
        'produit_id',
        'type',
        'quantite',
        'cout_unitaire_ht',
        'source_type',
        'source_id',
        'survenu_le',
        'cree_par',
        'note',
    ];

    protected $casts = [
        'type' => TypeMouvementStock::class,
        'quantite' => 'integer',
        'cout_unitaire_ht' => 'decimal:4',
        'survenu_le' => Instant::class,
    ];

    /**
     * Un mouvement garde son produit, même archivé depuis (voir les
     * SoftDeletes de Produit).
     */
    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}

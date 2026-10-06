<?php

namespace App\Domain\Stock\Models;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Casts\QuantiteArticles;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
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
    use BelongsToMagasin;

    protected $table = 'mouvements_stock';

    public const UPDATED_AT = null;

    protected $fillable = [
        'produit_id',
        'type',
        'quantite',
        'source_type',
        'source_id',
        'survenu_le',
        'cree_par',
        'note',
    ];

    protected $casts = [
        'type' => TypeMouvementStock::class,
        'quantite' => QuantiteArticles::class,
        'survenu_le' => 'datetime',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }
}

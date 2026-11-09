<?php

namespace App\Domain\Achats\Models;

use App\Domain\Achats\Concerns\EstUnTauxDuFournisseur;
use App\Domain\Calendrier\Casts\Instant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La TVA d'un avoir fournisseur à un taux (voir EstUnTauxDuFournisseur et
 * la migration create_taux_avoirs_fournisseur_table).
 */
class TauxAvoirFournisseur extends Model
{
    use EstUnTauxDuFournisseur;

    protected $table = 'taux_avoirs_fournisseur';

    // La confirmation d'un écart (ecart_confirme_*) : jamais en mass
    // assignment, seul TvaDuFournisseur la pose.
    protected $fillable = [
        'avoir_fournisseur_id',
        'taux_tva',
        'base_ht',
        'montant_tva',
        'montant_tva_calcule',
        'montant_tva_saisi',
        'ordre',
    ];

    /**
     * Le taux, sa base et ses TVA comme une colonne decimal(12,2)
     * (« 20.00 »), la confirmation d'un écart en UTC.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'taux_tva' => 'decimal:2',
            'base_ht' => 'decimal:2',
            'montant_tva' => 'decimal:2',
            'montant_tva_calcule' => 'decimal:2',
            'montant_tva_saisi' => 'decimal:2',
            'ecart_confirme_le' => Instant::class,
            'ordre' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AvoirFournisseur, $this>
     */
    public function avoirFournisseur(): BelongsTo
    {
        return $this->belongsTo(AvoirFournisseur::class);
    }
}

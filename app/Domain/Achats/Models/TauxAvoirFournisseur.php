<?php

namespace App\Domain\Achats\Models;

use App\Domain\Achats\Concerns\EstUnTauxDuFournisseur;
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

    public function avoirFournisseur(): BelongsTo
    {
        return $this->belongsTo(AvoirFournisseur::class);
    }
}

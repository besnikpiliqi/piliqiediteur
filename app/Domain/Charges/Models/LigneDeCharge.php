<?php

namespace App\Domain\Charges\Models;

use App\Domain\Charges\Enums\TauxDeCharge;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne d'une charge, pour un taux de TVA (voir Charge) : HT, TVA, TTC
 * et TVA déductible, en centimes, tels que la pièce les écrit (voir
 * ChiffrageDUneCharge). Elle ne se modifie jamais : la saisie suivante, ou
 * la correction d'une charge validée, réécrit toutes les lignes (voir
 * GardesDesCharges).
 *
 * @property int $charge_id
 * @property int $version
 * @property int $rang
 * @property TauxDeCharge $taux
 * @property int $ht
 * @property int $tva
 * @property int $ttc
 * @property int $tva_deductible
 * @property bool $tva_a_verifier
 */
class LigneDeCharge extends Model
{
    use BelongsToSociete;

    public $timestamps = false;

    protected $table = 'lignes_de_charge';

    protected $fillable = [];

    protected $casts = [
        'charge_id' => 'integer',
        'version' => 'integer',
        'rang' => 'integer',
        'taux' => TauxDeCharge::class,
        'ht' => 'integer',
        'tva' => 'integer',
        'ttc' => 'integer',
        'tva_deductible' => 'integer',
        'tva_a_verifier' => 'boolean',
    ];

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }
}

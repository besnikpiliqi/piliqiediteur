<?php

namespace App\Integrations\Pdp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only : jamais de mise à jour, uniquement des insertions via
 * TransmissionPdp::enregistrerChangementStatut(). Pas de BelongsToMagasin
 * ici -- toujours accédé via sa transmission parente, déjà scopée.
 */
class EvenementTransmissionPdp extends Model
{
    public const CREATED_AT = 'survenu_le';

    public const UPDATED_AT = null;

    protected $table = 'evenements_transmission_pdp';

    protected $fillable = [
        'transmission_pdp_id',
        'statut',
        'charge_utile',
        'survenu_le',
    ];

    protected $casts = [
        'charge_utile' => 'array',
    ];

    public function transmission(): BelongsTo
    {
        return $this->belongsTo(TransmissionPdp::class, 'transmission_pdp_id');
    }
}

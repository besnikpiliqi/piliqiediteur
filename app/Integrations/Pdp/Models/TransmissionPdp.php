<?php

namespace App\Integrations\Pdp\Models;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Integrations\Pdp\Enums\StatutTransmissionPdp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TransmissionPdp extends Model
{
    use BelongsToMagasin;

    protected $table = 'transmissions_pdp';

    protected $fillable = [
        'transmissible_type',
        'transmissible_id',
        'direction',
        'prestataire_pdp',
        'transmission_externe_id',
        'format',
        'statut',
        'chemin_fichier_archive',
        'code_erreur',
        'message_erreur',
        'soumise_le',
        'statut_maj_le',
    ];

    protected $casts = [
        'statut' => StatutTransmissionPdp::class,
        'soumise_le' => 'datetime',
        'statut_maj_le' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (TransmissionPdp $transmission) {
            $transmission->statut ??= StatutTransmissionPdp::Soumise;
        });
    }

    public function transmissible(): MorphTo
    {
        return $this->morphTo();
    }

    public function evenements(): HasMany
    {
        return $this->hasMany(EvenementTransmissionPdp::class);
    }

    public function enregistrerChangementStatut(StatutTransmissionPdp $statut, ?array $chargeUtile = null): void
    {
        $this->forceFill([
            'statut' => $statut,
            'statut_maj_le' => now(),
        ])->save();

        $this->evenements()->create([
            'statut' => $statut->value,
            'charge_utile' => $chargeUtile,
            'survenu_le' => now(),
        ]);
    }
}

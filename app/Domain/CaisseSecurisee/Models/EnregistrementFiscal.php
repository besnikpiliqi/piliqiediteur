<?php

namespace App\Domain\CaisseSecurisee\Models;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Exceptions\DonneeFiscaleInalterableException;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Une ligne du registre fiscal, écrite seulement par RegistreFiscal. Elle
 * ne se modifie ni ne se supprime : le modèle lève
 * DonneeFiscaleInalterableException, et les déclencheurs de la table
 * refusent ce qui ne passe pas par lui.
 *
 * `contenu` est le JSON exact qui est signé : il se relit tel quel pour
 * vérifier la signature, jamais après un décodage suivi d'un nouvel
 * encodage.
 *
 * @property ChaineFiscale $chaine
 * @property int $sequence
 * @property CarbonImmutable $horodatage
 */
class EnregistrementFiscal extends Model
{
    use BelongsToMagasin;

    protected $table = 'enregistrements_fiscaux';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'chaine' => ChaineFiscale::class,
        'sequence' => 'integer',
        'horodatage' => HorodatageUtc::class,
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw DonneeFiscaleInalterableException::enregistrementFiscal();
        });

        static::deleting(static function (): never {
            throw DonneeFiscaleInalterableException::enregistrementFiscal();
        });
    }

    public function operateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operateur_id');
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }
}

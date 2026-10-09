<?php

namespace App\Domain\CaisseSecurisee\Models;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * La ligne de verrou du registre fiscal d'un magasin, créée à son premier
 * verrou et écrite seulement par RegistreFiscal, qui la verrouille avant
 * toute écriture fiscale et y note l'instant de chaque verrou.
 *
 * @property CarbonImmutable|null $dernier_horodatage
 */
class TeteRegistreFiscal extends Model
{
    use BelongsToMagasin;

    protected $table = 'tetes_registre_fiscal';

    protected $fillable = [];

    protected $casts = [
        'dernier_horodatage' => HorodatageUtc::class,
    ];
}

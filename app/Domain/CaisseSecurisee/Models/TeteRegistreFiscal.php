<?php

namespace App\Domain\CaisseSecurisee\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Calendrier\Casts\JourCivil;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * La ligne de verrou du registre fiscal d'un magasin, créée à son premier
 * verrou et écrite seulement par RegistreFiscal, qui la verrouille avant
 * toute écriture fiscale, y note l'instant de chaque verrou et y tient
 * l'état des chaînes du magasin : la tête de chaque chaîne (et, pour la
 * chaîne des ventes, les grands totaux perpétuels réel et absolu, en
 * centimes), la version du module et la clé de signature du dernier
 * enregistrement. Ces trois dernières valeurs restent nulles tant que le
 * magasin n'a aucun enregistrement. Elle note aussi la dernière journée de
 * caisse clôturée (voir RegistreFiscal::derniereJourneeCloturee()).
 *
 * @property CarbonImmutable|null $dernier_horodatage
 * @property array<string, array{sequence: int, signature: string, gt_reel?: int, gt_absolu?: int}>|null $chaines par valeur de ChaineFiscale
 * @property string|null $version_module
 * @property string|null $cle_id
 * @property string|null $derniere_journee_cloturee AAAA-MM-JJ
 */
class TeteRegistreFiscal extends Model
{
    use BelongsToMagasin;

    protected $table = 'tetes_registre_fiscal';

    protected $fillable = [];

    protected $casts = [
        'dernier_horodatage' => Instant::class,
        'derniere_journee_cloturee' => JourCivil::class,
        'chaines' => 'array',
    ];
}

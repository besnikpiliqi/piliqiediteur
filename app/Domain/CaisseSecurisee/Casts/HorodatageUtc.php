<?php

namespace App\Domain\CaisseSecurisee\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Un instant fiscal, enregistré en UTC à la seconde (« AAAA-MM-JJ
 * HH:MM:SS », colonne dateTime) : un horodatage du registre fiscal, la
 * date d'émission d'un document signé. Le cast « datetime » de Laravel
 * écrirait l'heure de Paris sans son décalage : relue, une date UTC
 * reviendrait décalée d'une ou deux heures, et l'heure qui se répète la
 * nuit du passage à l'heure d'hiver (de 2 h à 3 h, le dernier dimanche
 * d'octobre) ne dirait plus de quel instant elle est.
 *
 * Relu en UTC (le registre), ou au fuseau passé en argument
 * (HorodatageUtc::class.':'.Calendrier::deParis()->fuseau()) : le même
 * instant, qui s'affiche alors à l'heure du calendrier.
 *
 * Seule une date qui porte son fuseau (DateTimeInterface) s'écrit : une
 * chaîne ne dit pas de quel fuseau est son heure.
 *
 * @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|null>
 */
class HorodatageUtc implements CastsAttributes
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly string $fuseauDeLecture = 'UTC') {}

    /**
     * L'instant tel qu'il s'écrit en base, et tel qu'une requête le
     * compare à la colonne.
     */
    public static function enBase(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format(self::FORMAT);
    }

    /**
     * L'instant d'un texte lu en base hors d'un modèle (un agrégat, une
     * lecture brute).
     */
    public static function depuisLaBase(string $texte): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!'.self::FORMAT, $texte, 'UTC');
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : self::depuisLaBase($value)->setTimezone($this->fuseauDeLecture);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException("L'horodatage fiscal {$key} s'écrit avec une date qui porte son fuseau, jamais avec une chaîne.");
        }

        return self::enBase($value);
    }
}

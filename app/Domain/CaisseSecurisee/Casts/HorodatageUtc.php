<?php

namespace App\Domain\CaisseSecurisee\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Un horodatage du registre fiscal, enregistré en UTC à la seconde
 * (« AAAA-MM-JJ HH:MM:SS ») et relu en UTC, quel que soit le fuseau de
 * l'application (Europe/Paris). Le cast « datetime » de Laravel écrirait
 * l'heure du fuseau de la date reçue et la relirait comme une heure de
 * Paris : une date UTC reviendrait décalée d'une ou deux heures.
 *
 * Seule une date qui porte son fuseau (DateTimeInterface) s'écrit : une
 * chaîne ne dit pas de quel fuseau est son heure.
 *
 * @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|null>
 */
class HorodatageUtc implements CastsAttributes
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::createFromFormat(self::FORMAT, $value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException("L'horodatage fiscal {$key} s'écrit avec une date qui porte son fuseau, jamais avec une chaîne.");
        }

        return CarbonImmutable::instance($value)->utc()->format(self::FORMAT);
    }
}

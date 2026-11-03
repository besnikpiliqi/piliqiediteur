<?php

namespace App\Domain\Calendrier\Casts;

use App\Domain\Calendrier\Calendrier;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Un jour du calendrier, « AAAA-MM-JJ » : une échéance, une journée de
 * caisse, une validité, la date d'un règlement ou d'un document. Il
 * s'écrit et se relit tel quel, sans heure ni fuseau, et se compare à un
 * autre jour comme une chaîne, dont l'ordre est celui du calendrier.
 * Seule une chaîne « AAAA-MM-JJ » s'écrit : une date qui porte une heure
 * ne dit pas de quel calendrier est son jour.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class JourCivil implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || Calendrier::jourValide($value) === null) {
            throw new InvalidArgumentException("Le jour {$key} s'écrit « AAAA-MM-JJ », jamais avec une date qui porte une heure.");
        }

        return $value;
    }
}

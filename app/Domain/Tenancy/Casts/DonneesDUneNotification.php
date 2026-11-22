<?php

namespace App\Domain\Tenancy\Casts;

use App\Domain\Tenancy\Support\LienRelatif;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * Le contenu d'une notification de la cloche (la colonne `data` de
 * `notifications`), rangé en JSON dont chaque lien vers une page de PILIQI
 * est relatif, jamais une adresse absolue (voir LienRelatif) : quelle que
 * soit la classe qui l'écrit, une notification rangée ne porte ni hôte ni
 * port, et ses boutons suivent le site qui sert la page.
 *
 * @implements CastsAttributes<array<array-key, mixed>, array<array-key, mixed>>
 */
final class DonneesDUneNotification implements CastsAttributes
{
    /**
     * @return array<array-key, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        try {
            $donnees = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $illisible) {
            throw new InvalidArgumentException("Le contenu de la notification n'est pas du JSON : {$illisible->getMessage()}", 0, $illisible);
        }

        return is_array($donnees) ? $donnees : [];
    }

    /**
     * @param  mixed  $value  le contenu de la notification, un tableau
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Le contenu {$key} d'une notification est un tableau.");
        }

        return json_encode(LienRelatif::dans($value), JSON_THROW_ON_ERROR);
    }
}

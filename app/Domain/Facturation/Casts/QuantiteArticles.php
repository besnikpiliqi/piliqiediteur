<?php

namespace App\Domain\Facturation\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Une quantité se lit en nombre entier d'articles (2, jamais "2.000") --
 * sauf une ancienne quantité à virgule, enregistrée avant le passage aux
 * quantités entières (voir la migration rendre_les_quantites_entieres), qui
 * se relit exactement (1.5) au lieu d'être tronquée en 1 : une facture déjà
 * émise ne doit jamais changer, ni à l'écran ni dans un calcul.
 *
 * Toute nouvelle quantité, elle, est entière (voir QuantiteEntiere).
 *
 * @implements CastsAttributes<int|float|null, int|float|string|null>
 */
class QuantiteArticles implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null ? null : self::normaliser($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null ? null : self::normaliser($value);
    }

    /**
     * Un entier quand la quantité est entière (2.000 -> 2), sa valeur
     * exacte au millième sinon (1.500 -> 1.5). Sert aussi aux sommes et
     * différences de quantités, pour ne jamais les tronquer avec (int).
     */
    public static function normaliser(mixed $quantite): int|float
    {
        $valeur = round((float) $quantite, 3);

        return floor($valeur) === $valeur ? (int) $valeur : $valeur;
    }
}

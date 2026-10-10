<?php

namespace App\Domain\Facturation\Casts;

use App\Domain\Facturation\Services\NumeroTva;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Le n° de TVA d'un magasin, d'un client ou d'un fournisseur, enregistré
 * sous une seule forme (voir NumeroTva::normaliser()) : en majuscules,
 * sans les espaces, points ni tirets de sa saisie. Il s'imprime ainsi sur
 * les documents et se compare tel quel, par exemple à celui du magasin
 * (voir HorsDeLEntrepriseDuMagasin). Vide, il s'enregistre NULL.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class NumeroTvaNormalise implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $numero = NumeroTva::normaliser((string) $value);

        return $numero === '' ? null : $numero;
    }
}

<?php

namespace App\Domain\Facturation\Rules;

use App\Domain\Facturation\Services\NumeroTva;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le n° de TVA saisi pour le magasin, un client ou un fournisseur : au
 * format de NumeroTva une fois débarrassé de ses espaces, points et
 * tirets, qui ne s'enregistrent pas (voir NumeroTvaNormalise).
 */
class NumeroTvaAuFormat implements ValidationRule
{
    public const MESSAGE = 'Le n° de TVA commence par les deux lettres du pays (FR...), suivies de 2 à 13 chiffres ou lettres ; les espaces, points et tirets ne comptent pas.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! NumeroTva::estAuFormat($value)) {
            $fail(self::MESSAGE);
        }
    }
}

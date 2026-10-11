<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Services\CodeMagasin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le code saisi à l'inscription d'une société pour son premier magasin :
 * lu en majuscules (« lyon1 » est LYON1), au format de CodeMagasin et
 * autre que DEMO, réservé à la démonstration, sans rien lire de la base.
 * La page d'inscription est ouverte à tous : elle ne dit rien des codes
 * d'une autre société. À la confirmation, le premier
 * magasin d'une société neuve reçoit ce code, rendu libre au besoin (voir
 * CodeMagasin::premierLibre()). Un magasin créé ensuite dans la société
 * vérifie aussi qu'il est libre (voir CodeMagasinLibre).
 */
class CodeMagasinAuFormat implements ValidationRule
{
    public const MESSAGE = 'Le code compte 2 à 10 lettres ou chiffres, sans espace ni tiret.';

    public const MESSAGE_RESERVE = 'Le code DEMO est réservé au magasin de démonstration : choisissez-en un autre.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CodeMagasin::estAuFormat($value)) {
            $fail(self::MESSAGE);
        } elseif (CodeMagasin::estReserve($value)) {
            $fail(self::MESSAGE_RESERVE);
        }
    }
}

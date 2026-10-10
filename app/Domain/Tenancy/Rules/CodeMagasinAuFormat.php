<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Services\CodeMagasin;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le code saisi à l'inscription d'un magasin : lu en majuscules (« lyon1 »
 * est LYON1) et au format de CodeMagasin, sans rien lire de la base. La
 * page d'inscription est ouverte à tous, et Livewire y laisse valider un
 * champ seul sans limite d'essais : un code déjà pris n'y est donc refusé
 * que par l'index unique de magasins.code, à la soumission, dont les
 * essais sont limités (voir RegisterMagasin). L'identité du magasin, elle,
 * vérifie aussi qu'il est libre (voir CodeMagasinLibre).
 */
class CodeMagasinAuFormat implements ValidationRule
{
    public const MESSAGE = 'Le code compte 2 à 10 lettres ou chiffres, sans espace ni tiret.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CodeMagasin::estAuFormat($value)) {
            $fail(self::MESSAGE);
        }
    }
}

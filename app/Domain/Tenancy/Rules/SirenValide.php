<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Services\IdentifiantsInsee;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le SIREN d'une société : 9 chiffres, clé de contrôle comprise (voir
 * IdentifiantsInsee).
 */
class SirenValide implements ValidationRule
{
    public const MESSAGE = 'Ce SIREN n\'est pas valide : il compte 9 chiffres, sans espace, et sa clé de contrôle ne correspond pas.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! IdentifiantsInsee::sirenValide($value)) {
            $fail(self::MESSAGE);
        }
    }
}

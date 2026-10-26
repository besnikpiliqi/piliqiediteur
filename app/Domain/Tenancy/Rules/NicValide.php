<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Services\IdentifiantsInsee;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le NIC d'un établissement : 5 chiffres, qui complètent le SIREN de la
 * société en un SIRET valide (voir SiretValide).
 */
class NicValide implements ValidationRule
{
    public const MESSAGE = 'Le NIC compte 5 chiffres : les derniers chiffres du SIRET de l\'établissement, après le SIREN.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! IdentifiantsInsee::nicValide($value)) {
            $fail(self::MESSAGE);
        }
    }
}

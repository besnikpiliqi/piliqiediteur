<?php

namespace App\Domain\Tenancy\Rules;

use App\Domain\Tenancy\Services\IdentifiantsInsee;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Le SIRET d'un magasin : 14 chiffres, clé de contrôle comprise (voir
 * IdentifiantsInsee), commençant par le SIREN de sa société quand il est
 * donné.
 */
class SiretValide implements ValidationRule
{
    public const MESSAGE = 'Ce SIRET n\'est pas valide : vérifiez le NIC de l\'établissement.';

    public const MESSAGE_AUTRE_SIREN = 'Ce SIRET n\'appartient pas à la société : il commence par son SIREN.';

    public function __construct(private readonly ?string $siren = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! IdentifiantsInsee::siretValide($value)) {
            $fail(self::MESSAGE);

            return;
        }

        if ($this->siren !== null && ! str_starts_with($value, $this->siren)) {
            $fail(self::MESSAGE_AUTRE_SIREN);
        }
    }
}

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
        $refus = $this->refus($value);

        if ($refus !== null) {
            $fail($refus);
        }
    }

    /**
     * Pourquoi ce SIRET est refusé, ou null s'il est valable : le contrôle
     * d'un formulaire (validate()) et celui d'une action qui réunit ses
     * propres refus (création, modification et changement de SIRET d'un
     * magasin).
     */
    public function refus(mixed $siret): ?string
    {
        return match (true) {
            ! is_string($siret) || ! IdentifiantsInsee::siretValide($siret) => self::MESSAGE,
            $this->siren !== null && ! str_starts_with($siret, $this->siren) => self::MESSAGE_AUTRE_SIREN,
            default => null,
        };
    }
}

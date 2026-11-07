<?php

namespace App\Domain\Facturation\Rules;

use App\Domain\Facturation\Services\SaisieDecimale;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Un prix d'achat ou un coût d'inventaire s'écrit tel que le fournisseur
 * l'écrit, six décimales au plus, jamais arrondi en silence (voir
 * SaisieDecimale::enMillioniemes()).
 */
class PrixDAchatEcrit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            SaisieDecimale::enMillioniemes($value);
        } catch (InvalidArgumentException $refus) {
            $fail($refus->getMessage());
        }
    }
}

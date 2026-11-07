<?php

namespace App\Domain\Facturation\Rules;

use App\Domain\Facturation\Services\SaisieDecimale;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Un montant recopié du document d'un fournisseur (le HT d'une ligne, la
 * TVA d'un taux, le total TTC saisi pour contrôle) s'écrit au centime,
 * jamais arrondi en silence (voir SaisieDecimale::montantAuCentime()).
 */
class MontantAuCentime implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            SaisieDecimale::montantAuCentime($value);
        } catch (InvalidArgumentException $refus) {
            $fail($refus->getMessage());
        }
    }
}

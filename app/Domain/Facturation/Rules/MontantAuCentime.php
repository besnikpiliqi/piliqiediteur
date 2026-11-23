<?php

namespace App\Domain\Facturation\Rules;

use App\Domain\Facturation\Services\SaisieDecimale;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Un montant qui s'écrit au centime, jamais arrondi en silence (voir
 * SaisieDecimale::montantAuCentime()) : celui que l'on recopie du
 * document d'un fournisseur (le HT d'une ligne, la TVA d'un taux, le
 * total TTC saisi pour contrôle), et chaque montant du barème des prix de
 * vente (voir BaremePrixDeVente::depuisTableau()).
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

<?php

namespace App\Domain\Facturation\Exceptions;

use App\Domain\Facturation\Models\LienDuDevis;
use RuntimeException;

/**
 * Le lien de réponse d'un devis ne vaut pas, ou plus : jeton inconnu,
 * lien retiré, remplacé par un nouvel envoi, devis déjà accepté, refusé,
 * expiré, transformé ou modifié. La page n'en dit jamais plus que
 * LienDuDevis::PLUS_VALABLE.
 */
final class LienDuDevisInvalideException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(LienDuDevis::PLUS_VALABLE);
    }
}

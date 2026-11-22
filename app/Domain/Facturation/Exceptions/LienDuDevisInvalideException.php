<?php

namespace App\Domain\Facturation\Exceptions;

use App\Domain\Facturation\Models\LienDuDevis;
use RuntimeException;

/**
 * Le lien de réponse d'un devis ne vaut pas, ou plus, pour répondre :
 * jeton inconnu, lien retiré, remplacé par un nouvel envoi, devis déjà
 * accepté, refusé, expiré, transformé ou modifié. La page n'en dit jamais
 * plus que LienDuDevis::PLUS_VALABLE, sauf pour le lien qui a lui-même
 * enregistré l'acceptation, dont elle montre la confirmation tant que le
 * devis la garde (voir LienDuDevis::confirmeLAcceptation()).
 */
final class LienDuDevisInvalideException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(LienDuDevis::PLUS_VALABLE);
    }
}

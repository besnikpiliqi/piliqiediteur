<?php

namespace App\Domain\Charges\Enums;

use Filament\Support\Contracts\HasColor;

/**
 * Où en est la vérification du crédit de TVA qu'une société a déclaré
 * (lot TVA27, décision de l'éditeur du 15/11/2026 : « le crédit de la TVA
 * doit être conseillé vérifié par l'expert-comptable »), lu à chaque
 * affichage (voir CreditsDeTva::etat()) :
 * - vérifié par le comptable : le cabinet lié ou l'expert-comptable
 *   déclaré l'a confirmé, corrigé, ou saisi lui-même ;
 * - à vérifier par votre comptable : le propriétaire l'a saisi ou changé,
 *   et la société a un comptable qui valide ses charges ;
 * - non vérifié par un comptable : la société n'a pas de comptable ; dès
 *   qu'il en arrive un, il passe « à vérifier ».
 */
enum EtatDuCreditDeTva: string implements HasColor
{
    case Verifie = 'verifie';
    case AVerifier = 'a_verifier';
    case NonVerifie = 'non_verifie';

    public function getColor(): string
    {
        return match ($this) {
            self::Verifie => 'success',
            self::AVerifier => 'info',
            self::NonVerifie => 'warning',
        };
    }
}

<?php

namespace App\Domain\Facturation\Exceptions;

use App\Domain\Facturation\Enums\NatureOperation;
use LogicException;

/**
 * Une vente hors de la TVA française ne s'émet que si toutes les
 * conditions de sa nature sont réunies (I1, I2, voir
 * ControleNatureOperation) : sinon elle reste un brouillon, sans numéro
 * consommé ni signature, et le refus dit tout ce qui manque d'un coup.
 */
class NatureOperationRefuseeException extends LogicException
{
    /**
     * @param  list<string>  $manquements  tout ce qui empêche l'émission, chacun en une phrase sans point final
     */
    public function __construct(public readonly NatureOperation $nature, public readonly array $manquements)
    {
        parent::__construct("Impossible d'émettre en « {$nature->getLabel()} » : ".implode(' ; ', $manquements).'. Choisissez « '.NatureOperation::VenteEnFrance->getLabel().' » (TVA française) si ces conditions ne sont pas remplies.');
    }
}

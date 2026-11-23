<?php

namespace App\Domain\Facturation\Exceptions;

use LogicException;

/**
 * Un téléchargement par période en « PDF (ZIP) » dont les documents choisis
 * ne sont que des charges sans pièce à servir (décision de l'éditeur du
 * 15/11/2026, lot DATEOP) : une charge déposée sans sa pièce, ou dont la
 * pièce est bloquée par l'antivirus, n'a rien dans le ZIP, qui serait vide.
 * C'est un refus, jamais une erreur à rejouer : la demande le dit à la
 * personne (voir DemanderLeTelechargementDesDocumentsAction), et une pièce
 * bloquée pendant la préparation clôt la demande pour ce motif (voir
 * PreparerLeTelechargementDesDocumentsJob). Le récapitulatif CSV, lui, les
 * liste et dit leur pièce « Manquante ».
 */
final class AucunePieceDeChargeAServirException extends LogicException
{
    public const MESSAGE = 'Aucune des charges choisies n\'a de pièce à mettre dans le ZIP : choisissez « Récapitulatif (CSV) », qui les liste et dit leur pièce manquante.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}

<?php

namespace App\Domain\Charges\Exceptions;

use LogicException;

/**
 * Une saisie de charge qui demande une confirmation avant de s'enregistrer
 * (décision de l'éditeur du 14/11/2026, lot CHARGES) : rien n'est
 * enregistré, et l'écran dit pourquoi et propose de confirmer. Jamais un
 * refus définitif :
 * - une TVA ou un TTC qui s'écarte de plus d'un centime de ce que calcule
 *   PILIQI : confirmés, ils s'enregistrent tels que la pièce les écrit, et
 *   la ligne est marquée « TVA à vérifier » (voir ChiffrageDUneCharge) ;
 * - une charge sans numéro de pièce du même fournisseur, du même mois de
 *   TVA et du même TTC qu'une autre de la société : un doublon probable
 *   (voir ChargesDeLaSociete::doublonProbable()).
 */
final class ConfirmationRequiseException extends LogicException
{
    public const ECARTS = 'ecarts';

    public const DOUBLON = 'doublon';

    public const CONSIGNE_ECARTS = 'Pour garder les montants de la pièce, confirmez-les : la ligne sera marquée « TVA à vérifier ».';

    public const CONSIGNE_DOUBLON = 'Vérifiez que ce n\'est pas la même pièce, puis confirmez pour l\'enregistrer quand même.';

    /**
     * @param  self::ECARTS|self::DOUBLON  $motif
     */
    public function __construct(public readonly string $motif, string $message)
    {
        parent::__construct($message);
    }
}

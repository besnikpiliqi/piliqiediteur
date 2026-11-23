<?php

namespace App\Domain\Charges\Enums;

/**
 * Ce que la TVA d'une période doit au crédit de TVA déclaré par la société
 * (lot TVA27, voir ReportDuCreditDeTva) :
 * - sans crédit déclaré : la période vient avant celle de la déclaration
 *   qui sert d'ancre, ou aucune déclaration n'est faite ; son crédit
 *   reporté ne vient que des périodes que PILIQI a comptées depuis
 *   l'arrivée de la société, à partir de zéro ;
 * - déclarée : la période de la déclaration qui sert d'ancre, dont le
 *   crédit à reporter est celui que la société a déclaré, et sa TVA à
 *   payer celle de sa déclaration, que PILIQI ne connaît pas ;
 * - avec le crédit déclaré : une période qui suit celle de l'ancre, dont
 *   le crédit reporté part du crédit déclaré.
 */
enum StatutDuReport: string
{
    case SansCreditDeclare = 'sans_credit_declare';
    case Declaree = 'declaree';
    case AvecCreditDeclare = 'avec_credit_declare';
}

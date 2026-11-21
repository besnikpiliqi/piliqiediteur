<?php

namespace App\Domain\Facturation\Enums;

/**
 * Pourquoi un avoir régularise une facture (voir
 * Avoir::estUneRegularisation()) : dans les deux cas, il crédite ce qui
 * reste à créditer de la facture sans rien rendre au stock, et son crédit
 * ne règle que cette facture, puis sa facture rectificative.
 *
 * - SirenCorrige : après la correction exceptionnelle du SIREN de la
 *   société par l'éditeur (voir RegularisationDUneFacture) ; l'avoir
 *   imprime l'émetteur que la facture a signé, l'ancien SIREN, et la
 *   mention du SIREN correct.
 * - ExonerationNonProuvee : une vente exonérée (export hors de l'Union,
 *   livraison vers un DOM) dont la preuve de sortie manque 150 jours après
 *   sa date (voir RegularisationDUneExoneration) ; l'avoir s'émet sous
 *   l'identité actuelle de la société, comme tout avoir, avec la mention
 *   de la preuve non reçue.
 */
enum MotifDeRegularisation: string
{
    case SirenCorrige = 'siren_corrige';
    case ExonerationNonProuvee = 'exoneration_non_prouvee';
}

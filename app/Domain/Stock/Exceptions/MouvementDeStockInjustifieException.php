<?php

namespace App\Domain\Stock\Exceptions;

use LogicException;

/**
 * Un mouvement du grand livre que ne justifie pas le document de son
 * magasin que son type désigne (voir ProvenanceDesMouvements), refusé
 * avant toute écriture ; la base le refuserait de toute façon (voir
 * GardesDuStock).
 */
final class MouvementDeStockInjustifieException extends LogicException
{
    public const SANS_DOCUMENT = 'Ce mouvement de stock n\'est justifié par aucun document du magasin dans l\'état voulu, qui porte ce produit.';

    public const SANS_MOTIF = 'Un ajustement du stock corrige une facture d\'achat validée et porte son motif.';

    public const AU_DELA_DU_DOCUMENT = 'Ce mouvement de stock va au-delà de ce que porte son document.';

    public const SANS_AUTEUR = 'Ce mouvement de stock se fait au nom de la personne qui fait son document.';
}

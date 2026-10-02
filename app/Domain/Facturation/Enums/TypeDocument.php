<?php

namespace App\Domain\Facturation\Enums;

/**
 * Clé de numérotation (table sequences_documents). Chaque type a sa propre
 * séquence, indépendante par magasin ET par type -- un devis et une
 * facture du même magasin peuvent porter le même numéro sans collision.
 */
enum TypeDocument: string
{
    case Devis = 'devis';
    case Facture = 'facture';
    case Avoir = 'avoir';
    case FactureAchat = 'facture_achat';
    case ClotureCaisse = 'cloture_caisse';
}

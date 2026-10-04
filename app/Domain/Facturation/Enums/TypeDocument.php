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
    // Vente comptoir à un client particulier (voir
    // Facture::relevantDeLeInvoicing()) : même table `factures`, mais une
    // série de numérotation distincte de celle des vraies factures --
    // deux séries séparées, chacune continue, sont légalement admises
    // (contrairement à un mélange des deux dans une même série).
    case TicketCaisse = 'ticket_caisse';
    case Avoir = 'avoir';
    case FactureAchat = 'facture_achat';
    case AvoirFournisseur = 'avoir_fournisseur';
    case ClotureCaisse = 'cloture_caisse';
    case SoumissionEReporting = 'soumission_ereporting';
}

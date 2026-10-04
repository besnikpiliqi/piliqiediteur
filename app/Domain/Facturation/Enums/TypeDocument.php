<?php

namespace App\Domain\Facturation\Enums;

/**
 * Clé de numérotation (table sequences_documents). Chaque type a sa propre
 * séquence, indépendante par magasin ET par type -- un devis et une
 * facture du même magasin peuvent porter le même numéro sans collision.
 * Sert aussi, au-delà des documents fiscaux à proprement parler, à toute
 * entité ayant besoin d'une suite numérique fiable par magasin (voir
 * Produit, dont la référence se génère automatiquement si elle n'est pas
 * saisie) : DocumentNumberingService n'est qu'un compteur générique
 * sûr en concurrence, pas une notion exclusivement fiscale.
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
    case Produit = 'produit';
}

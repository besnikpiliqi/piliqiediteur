<?php

namespace App\Domain\Facturation\Enums;

/**
 * Clé de numérotation (table sequences_documents) : chaque type a sa
 * propre série dans chaque magasin, et chaque année pour les documents
 * (voir DocumentNumberingService) -- un devis et une facture du même
 * magasin ont chacun leur rang 000001, distingués par le code de leur
 * type. Sert aussi aux références de produits (PRD-000001), générées
 * quand elles ne sont pas saisies, une suite par magasin jamais remise à
 * zéro : DocumentNumberingService n'est qu'un compteur sûr en
 * concurrence, pas une notion exclusivement fiscale.
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

    /**
     * Le premier segment de chaque numéro de la série, avant le code du
     * magasin et l'année (TK dans TK-LYON1-2026-000001, voir
     * DocumentNumberingService) : il dit pour toujours dans quelle série
     * un document a été numéroté (voir Facture::serie()).
     */
    public function code(): string
    {
        return match ($this) {
            self::Devis => 'DEV',
            self::Facture => 'FAC',
            self::TicketCaisse => 'TK',
            self::Avoir => 'AV',
            self::FactureAchat => 'FA',
            self::AvoirFournisseur => 'AF',
            self::ClotureCaisse => 'Z',
            self::SoumissionEReporting => 'ER',
            self::Produit => 'PRD',
        };
    }
}

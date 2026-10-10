<?php

namespace App\Domain\CaisseSecurisee\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Les codes du journal des événements techniques (JET), chaîne
 * ChaineFiscale::Evenement du registre fiscal : ceux du dictionnaire
 * NF525 R19, avec un libellé en français. Un code n'y figure qu'une fois
 * que le logiciel l'inscrit.
 *
 * - RegistreFiscal inscrit de lui-même, avant l'enregistrement qui les
 *   rend nécessaires, l'initialisation au tout premier enregistrement du
 *   magasin, le changement de version du module fiscal
 *   (ModuleFiscal::VERSION) et celui de la clé de signature (270).
 * - L'ouverture de session suit le premier accès d'une session au panneau
 *   de son magasin (JournaliserOuvertureDeSession), et la fin de session
 *   la déconnexion, dans le magasin ouvert par la session
 *   (JournaliserFinDeSession).
 * - L'export de données suit chaque export du journal des événements
 *   (ExporterJournalDesEvenementsAction).
 * - La modification des droits suit la création, la modification (rôle,
 *   statut, autorisations) et le retrait d'un membre de l'équipe
 *   (JournalDesDroits).
 * - Un paramètre ayant un impact sur la conformité suit aussi le
 *   changement du régime de TVA du magasin, de son option pour les débits
 *   et du taux de TVA d'un produit (JournaliseSesParametresDeConformite).
 * - L'annulation de ticket suit l'émission d'un avoir sur des tickets de
 *   caisse (voir EmettreAvoirAction).
 * - La vente abandonnée avant validation suit la suppression d'un
 *   brouillon de facture ou de ticket (voir Facture::delete()) et
 *   l'abandon d'une vente à la caisse ; la ligne retirée d'une vente avant
 *   validation, chaque ligne renseignée retirée du panier de la caisse
 *   (voir Caisse).
 * - Chaque vérification de l'intégrité du registre (voir
 *   VerifierIntegriteAction) inscrit ses anomalies, une rupture de
 *   séquence ou de numérotation (95) ou un autre défaut d'intégrité (90),
 *   puis l'opération de contrôle elle-même (280), dont les points de
 *   reprise servent de départ à la vérification incrémentale suivante.
 */
enum CodeEvenement: int implements HasLabel
{
    case FinDeSession = 40;
    case OuvertureDeSession = 80;
    case DefautDIntegrite = 90;
    case RuptureDeSequence = 95;
    case ExportDeDonnees = 110;
    case ModificationDesDroits = 130;
    case AnnulationDeTicket = 190;
    case VersionMajeure = 250;
    case VersionMineure = 255;
    case Initialisation = 260;
    case ParametreDeConformite = 270;
    case OperationDeControle = 280;
    case TransactionAbandonnee = 320;
    case LigneRetiree = 323;

    public function getLabel(): string
    {
        return match ($this) {
            self::FinDeSession => 'Fin de session (déconnexion)',
            self::OuvertureDeSession => 'Ouverture de session dans le magasin',
            self::DefautDIntegrite => 'Défaut d\'intégrité détecté par la vérification',
            self::RuptureDeSequence => 'Rupture de séquence ou de numérotation détectée par la vérification',
            self::ExportDeDonnees => 'Export de données',
            self::ModificationDesDroits => 'Modification des droits d\'un utilisateur',
            self::AnnulationDeTicket => 'Annulation de ticket (avoir sur des tickets de caisse)',
            self::VersionMajeure => 'Changement de version majeure du module fiscal',
            self::VersionMineure => 'Changement de version mineure du module fiscal',
            self::Initialisation => 'Initialisation du registre fiscal du magasin',
            self::ParametreDeConformite => 'Modification d\'un paramètre ayant un impact sur la conformité',
            self::OperationDeControle => 'Opération de contrôle (vérification de l\'intégrité)',
            self::TransactionAbandonnee => 'Vente abandonnée avant validation',
            self::LigneRetiree => 'Ligne retirée d\'une vente avant validation',
        };
    }

    /**
     * Le code suivi de son libellé, tel que l'écran et l'export du journal
     * l'affichent : « 270 – Modification d'un paramètre... ».
     */
    public function libelleAvecCode(): string
    {
        return "{$this->value} – {$this->getLabel()}";
    }
}

<?php

namespace App\Domain\Tenancy\Enums;

use App\Domain\Authorization\PermissionRegistry;
use Filament\Support\Contracts\HasLabel;

/**
 * Le rôle d'un membre, sur le pivot magasin_user : un libellé, et le
 * modèle des autorisations cochées pour lui à sa création ou quand le
 * propriétaire change son rôle (voir permissionsParDefaut()). Le
 * propriétaire coche ou décoche ensuite ce qu'il veut pour chacun (voir
 * CreerMembreMagasinAction et MettreAJourMembreMagasinAction).
 *
 * Seul le propriétaire a un rôle Spatie, 'owner', qui porte toutes les
 * autorisations du magasin (voir SeedMagasinRolesAction) ; un autre membre
 * a les autorisations cochées pour lui, données directement dans le
 * magasin. Le rôle propriétaire ne s'obtient qu'en créant le magasin.
 */
enum RoleMagasin: string implements HasLabel
{
    case Proprietaire = 'owner';
    case Administrateur = 'admin';
    case Comptable = 'accountant';
    case Vendeur = 'employee';
    case Observateur = 'viewer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proprietaire => 'Propriétaire',
            self::Administrateur => 'Administrateur',
            self::Comptable => 'Comptable',
            self::Vendeur => 'Vendeur',
            self::Observateur => 'Observateur',
        };
    }

    /**
     * Rôles qu'un propriétaire peut attribuer à un membre de son équipe.
     * "owner" est exclu : il n'existe qu'un seul chemin pour le devenir
     * (la création du magasin elle-même), jamais via la gestion d'équipe.
     *
     * @return list<self>
     */
    public static function assignables(): array
    {
        return [self::Administrateur, self::Comptable, self::Vendeur, self::Observateur];
    }

    /**
     * Les autorisations cochées d'office pour ce rôle. Le propriétaire les
     * a toutes ; l'administrateur aussi, sauf les paramètres du magasin
     * (identité, TVA, échéance et mentions des factures).
     *
     * @return list<string>
     */
    public function permissionsParDefaut(): array
    {
        $lecture = [
            PermissionRegistry::CLIENT_VIEW,
            PermissionRegistry::FOURNISSEUR_VIEW,
            PermissionRegistry::PRODUIT_VIEW,
            PermissionRegistry::DEVIS_VIEW,
            PermissionRegistry::FACTURE_VIEW,
            // Les achats sont des documents de coût internes, pas des
            // documents de vente : pas de confidentialité par créateur
            // (voir PermissionRegistry), donc une simple permission de
            // lecture d'équipe suffit pour le comptable et l'observateur.
            PermissionRegistry::FACTURE_ACHAT_VIEW,
            PermissionRegistry::AVOIR_FOURNISSEUR_VIEW,
        ];

        return match ($this) {
            self::Proprietaire => PermissionRegistry::toutes(),
            self::Administrateur => array_values(array_diff(
                PermissionRegistry::toutes(),
                [PermissionRegistry::PARAMETRES_GERER],
            )),
            // Le comptable voit l'intégralité des factures du magasin
            // (indispensable pour son travail), pas seulement les siennes.
            // Il suit aussi les impayés : il envoie et relance factures et
            // avoirs, sans régler les relances automatiques, qui écrivent
            // aux clients au nom du magasin.
            self::Comptable => [
                ...$lecture,
                PermissionRegistry::FACTURE_VIEW_TOUTES,
                PermissionRegistry::FACTURE_ENVOYER,
                PermissionRegistry::PAIEMENT_VIEW,
                PermissionRegistry::PAIEMENT_CREER,
                PermissionRegistry::AVOIR_VIEW,
                PermissionRegistry::AVOIR_ENVOYER,
                PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK,
                PermissionRegistry::CLOTURE_CAISSE_VIEW,
                PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES,
                // Le registre fiscal et le journal des événements, qu'il
                // présente à l'administration avec le propriétaire.
                PermissionRegistry::CAISSE_SECURISEE_CONSULTER,
            ],
            // Un vendeur crée/émet ses propres devis et factures, mais ne
            // voit que celles qu'il a lui-même créées (voir FacturePolicy)
            // et ne les corrige pas par un avoir. Clôturer LA JOURNÉE DU
            // MAGASIN (ClotureCaisse) ne lui est pas coché non plus ; il
            // ferme en revanche toujours SA PROPRE caisse (voir
            // FermerSaCaisseAction), sans autorisation dédiée puisqu'elle
            // ne concerne que ses propres ventes.
            self::Vendeur => [
                PermissionRegistry::CLIENT_VIEW,
                PermissionRegistry::CLIENT_CREATE,
                PermissionRegistry::CLIENT_UPDATE,
                PermissionRegistry::PRODUIT_VIEW,
                PermissionRegistry::DEVIS_VIEW,
                PermissionRegistry::DEVIS_CREATE,
                PermissionRegistry::DEVIS_UPDATE,
                PermissionRegistry::DEVIS_ENVOYER,
                PermissionRegistry::FACTURE_VIEW,
                PermissionRegistry::FACTURE_CREATE,
                PermissionRegistry::FACTURE_EMETTRE,
                // Envoyer par e-mail, et relancer, les factures qu'il voit,
                // donc les siennes (voir FacturePolicy::envoyer()).
                PermissionRegistry::FACTURE_ENVOYER,
                PermissionRegistry::PAIEMENT_VIEW,
                PermissionRegistry::PAIEMENT_CREER,
            ],
            self::Observateur => $lecture,
        };
    }
}

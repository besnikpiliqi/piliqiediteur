<?php

namespace App\Domain\Tenancy\Enums;

use App\Domain\Authorization\PermissionRegistry;
use Filament\Support\Contracts\HasLabel;

/**
 * Le rôle d'un membre dans un magasin (voir Affectation::$role) :
 * - responsable : il a toutes les autorisations de son magasin, sans
 *   aucune case (voir User::autorisationsDans()), et jamais celles de la
 *   société ; nommé par le propriétaire, ou par un administrateur autorisé
 *   à nommer les responsables dans un magasin de sa portée ;
 * - vendeur, observateur : le rôle n'est qu'un modèle qui précoche des
 *   autorisations (voir permissionsParDefaut()) ; le propriétaire ou un
 *   responsable du magasin coche ou décoche ensuite ce qu'il veut, pour ce
 *   seul magasin, jamais au-delà de ses propres autorisations (voir
 *   PorteeDesDroits).
 *
 * Le propriétaire, un administrateur et un expert-comptable n'ont aucun
 * rôle de magasin (voir RoleSociete) : la base refuse toute autre valeur
 * (voir GardesDesDroits).
 */
enum RoleMagasin: string implements HasLabel
{
    case Responsable = 'responsable';
    case Vendeur = 'vendeur';
    case Observateur = 'observateur';

    public function getLabel(): string
    {
        return match ($this) {
            self::Responsable => 'Responsable',
            self::Vendeur => 'Vendeur',
            self::Observateur => 'Observateur',
        };
    }

    /**
     * Ce rôle porte-t-il des cases ? Pas le responsable, qui a toutes les
     * autorisations de son magasin.
     */
    public function aDesCases(): bool
    {
        return $this !== self::Responsable;
    }

    /**
     * Les autorisations que ce modèle précoche. Aucune pour le
     * responsable, qui n'a pas de case.
     *
     * @return list<string>
     */
    public function permissionsParDefaut(): array
    {
        return match ($this) {
            self::Responsable => [],
            // Un vendeur crée/émet ses propres devis et factures, mais ne
            // voit et n'encaisse que celles qu'il a lui-même créées (voir
            // FacturePolicy) et ne les corrige pas par un avoir. Clôturer LA JOURNÉE DU
            // MAGASIN (ClotureCaisse) ne lui est pas coché non plus ; il
            // ferme en revanche toujours SA PROPRE caisse (voir
            // FermerSaCaisseAction), sans autorisation dédiée puisqu'elle
            // ne concerne que ses propres ventes et encaissements (voir
            // ClotureCaissePersonnellePolicy).
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
            self::Observateur => [
                PermissionRegistry::CLIENT_VIEW,
                PermissionRegistry::FOURNISSEUR_VIEW,
                PermissionRegistry::PRODUIT_VIEW,
                PermissionRegistry::DEVIS_VIEW,
                PermissionRegistry::FACTURE_VIEW,
                // Les achats sont des documents de coût internes, pas des
                // documents de vente : pas de confidentialité par créateur
                // (voir PermissionRegistry), donc une simple permission de
                // lecture d'équipe suffit.
                PermissionRegistry::FACTURE_ACHAT_VIEW,
                PermissionRegistry::AVOIR_FOURNISSEUR_VIEW,
            ],
        };
    }
}

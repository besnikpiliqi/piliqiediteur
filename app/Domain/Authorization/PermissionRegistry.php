<?php

namespace App\Domain\Authorization;

/**
 * Liste centrale des permissions de l'application. Aucune chaîne magique
 * ne doit être écrite ailleurs ("client.create", ...) : on référence
 * toujours ces constantes, pour que renommer/retrouver une permission
 * reste sûr (IDE, recherche, refactor).
 *
 * Ces permissions sont scopées par magasin (Spatie Permission en mode
 * teams, voir config/permission.php) : accorder "client.create" à un
 * rôle dans le magasin A n'accorde rien dans le magasin B.
 */
final class PermissionRegistry
{
    // Clients
    public const CLIENT_VIEW = 'client.view';

    public const CLIENT_CREATE = 'client.create';

    public const CLIENT_UPDATE = 'client.update';

    public const CLIENT_DELETE = 'client.delete';

    // Fournisseurs
    public const FOURNISSEUR_VIEW = 'fournisseur.view';

    public const FOURNISSEUR_CREATE = 'fournisseur.create';

    public const FOURNISSEUR_UPDATE = 'fournisseur.update';

    public const FOURNISSEUR_DELETE = 'fournisseur.delete';

    // Produits
    public const PRODUIT_VIEW = 'produit.view';

    public const PRODUIT_CREATE = 'produit.create';

    public const PRODUIT_UPDATE = 'produit.update';

    public const PRODUIT_DELETE = 'produit.delete';

    public const PRODUIT_AJUSTER_STOCK = 'produit.ajuster_stock';

    // Devis
    public const DEVIS_VIEW = 'devis.view';

    public const DEVIS_CREATE = 'devis.create';

    public const DEVIS_UPDATE = 'devis.update';

    public const DEVIS_ENVOYER = 'devis.envoyer';

    // Factures
    public const FACTURE_VIEW = 'facture.view';

    // Sans cette permission, un utilisateur ne voit que les factures
    // qu'il a lui-même créées (voir FacturePolicy) -- confidentialité du
    // CA par vendeur, décision prise explicitement avec le client.
    public const FACTURE_VIEW_TOUTES = 'facture.view_toutes';

    public const FACTURE_CREATE = 'facture.create';

    public const FACTURE_EMETTRE = 'facture.emettre';

    public const FACTURE_ANNULER = 'facture.annuler';

    // Mandat PDP -- réservé au propriétaire, voir SeedMagasinRolesAction
    public const MANDAT_PDP_GERER = 'mandat_pdp.gerer';

    // Équipe (membres du magasin) -- réservé au propriétaire et à
    // l'administrateur, voir SeedMagasinRolesAction
    public const EQUIPE_VIEW = 'equipe.view';

    public const EQUIPE_CREER = 'equipe.creer';

    public const EQUIPE_MODIFIER = 'equipe.modifier';

    // Paiements -- ni modification ni suppression après coup (voir
    // Paiement::$fillable et PaiementPolicy) : une erreur de saisie se
    // corrige par un nouveau paiement, jamais en réécrivant l'historique
    // d'encaissement.
    public const PAIEMENT_VIEW = 'paiement.view';

    public const PAIEMENT_CREER = 'paiement.creer';

    /**
     * @return list<string>
     */
    public static function toutes(): array
    {
        return [
            self::CLIENT_VIEW, self::CLIENT_CREATE, self::CLIENT_UPDATE, self::CLIENT_DELETE,
            self::FOURNISSEUR_VIEW, self::FOURNISSEUR_CREATE, self::FOURNISSEUR_UPDATE, self::FOURNISSEUR_DELETE,
            self::PRODUIT_VIEW, self::PRODUIT_CREATE, self::PRODUIT_UPDATE, self::PRODUIT_DELETE, self::PRODUIT_AJUSTER_STOCK,
            self::DEVIS_VIEW, self::DEVIS_CREATE, self::DEVIS_UPDATE, self::DEVIS_ENVOYER,
            self::FACTURE_VIEW, self::FACTURE_VIEW_TOUTES, self::FACTURE_CREATE, self::FACTURE_EMETTRE, self::FACTURE_ANNULER,
            self::MANDAT_PDP_GERER,
            self::EQUIPE_VIEW, self::EQUIPE_CREER, self::EQUIPE_MODIFIER,
            self::PAIEMENT_VIEW, self::PAIEMENT_CREER,
        ];
    }
}

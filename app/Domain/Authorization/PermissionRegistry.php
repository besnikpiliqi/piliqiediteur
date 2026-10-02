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

    // Mandat PDP -- réservé au propriétaire, voir RoleSeeder
    public const MANDAT_PDP_GERER = 'mandat_pdp.gerer';

    /**
     * @return list<string>
     */
    public static function toutes(): array
    {
        return [
            self::CLIENT_VIEW, self::CLIENT_CREATE, self::CLIENT_UPDATE, self::CLIENT_DELETE,
            self::FOURNISSEUR_VIEW, self::FOURNISSEUR_CREATE, self::FOURNISSEUR_UPDATE, self::FOURNISSEUR_DELETE,
            self::PRODUIT_VIEW, self::PRODUIT_CREATE, self::PRODUIT_UPDATE, self::PRODUIT_DELETE, self::PRODUIT_AJUSTER_STOCK,
            self::MANDAT_PDP_GERER,
        ];
    }
}

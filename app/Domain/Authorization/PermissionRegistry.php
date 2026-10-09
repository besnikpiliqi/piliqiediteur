<?php

namespace App\Domain\Authorization;

use InvalidArgumentException;

/**
 * Liste centrale des permissions de l'application. Aucune chaîne magique
 * ne doit être écrite ailleurs ("client.create", ...) : on référence
 * toujours ces constantes, pour que renommer/retrouver une permission
 * reste sûr (IDE, recherche, refactor).
 *
 * Ces permissions sont scopées par magasin (Spatie Permission en mode
 * teams, voir config/permission.php) : accorder "client.create" à un
 * membre dans le magasin A n'accorde rien dans le magasin B. Le
 * propriétaire les a toutes, par son rôle 'owner' (voir
 * SeedMagasinRolesAction) ; un membre de l'équipe a celles que le
 * propriétaire coche pour lui, à partir du modèle de son rôle (voir
 * RoleMagasin::permissionsParDefaut() et MettreAJourMembreMagasinAction).
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

    // Produits -- et services (voir ServicePolicy) : tout le catalogue
    public const PRODUIT_VIEW = 'produit.view';

    public const PRODUIT_CREATE = 'produit.create';

    public const PRODUIT_UPDATE = 'produit.update';

    public const PRODUIT_DELETE = 'produit.delete';

    /**
     * Gérer le stock du magasin : reçoit les alertes de stock bas (voir
     * NotifierLeStockSousLeSeuilDAlerte).
     */
    public const PRODUIT_AJUSTER_STOCK = 'produit.ajuster_stock';

    // Un vendeur voit le prix/stock de chaque produit (nécessaire pour
    // vendre), mais pas la valorisation globale du stock du magasin --
    // information sensible : parmi les membres, cochée d'office pour
    // l'administrateur et le comptable seulement (voir
    // RoleMagasin::permissionsParDefaut() et ProduitResource).
    public const PRODUIT_VOIR_VALEUR_STOCK = 'produit.voir_valeur_stock';

    /**
     * Régler le barème qui calcule les prix de vente (voir
     * BaremePrixDeVente) : il fixe les prix de tout le magasin.
     */
    public const PRIX_VENTE_REGLER = 'prix_vente.regler';

    // Devis
    public const DEVIS_VIEW = 'devis.view';

    public const DEVIS_CREATE = 'devis.create';

    public const DEVIS_UPDATE = 'devis.update';

    // Envoyer un devis : le numéroter, et l'envoyer par e-mail à son
    // client (voir EnvoyerDocumentAction).
    public const DEVIS_ENVOYER = 'devis.envoyer';

    // Factures
    public const FACTURE_VIEW = 'facture.view';

    // Sans cette permission, un utilisateur ne voit que les factures
    // qu'il a lui-même créées (voir FacturePolicy) -- confidentialité du
    // CA par vendeur, décision prise explicitement avec le client.
    public const FACTURE_VIEW_TOUTES = 'facture.view_toutes';

    public const FACTURE_CREATE = 'facture.create';

    public const FACTURE_EMETTRE = 'facture.emettre';

    // Envoyer une facture émise par e-mail et relancer une facture
    // impayée (voir EnvoyerDocumentAction), parmi celles que
    // l'utilisateur voit (voir FacturePolicy::envoyer()).
    public const FACTURE_ENVOYER = 'facture.envoyer';

    /**
     * Régler les relances automatiques des factures impayées (Paramètres >
     * Relances) : elles écrivent aux clients au nom du magasin.
     */
    public const RELANCES_REGLER = 'relances.regler';

    /**
     * Régler les paramètres du magasin : son identité légale
     * (IdentiteMagasin) et sa TVA (ParametresFiscaux). Aucun modèle de
     * rôle ne la coche d'office : le propriétaire la détient, et la coche
     * pour le membre de son choix.
     */
    public const PARAMETRES_GERER = 'parametres.gerer';

    // Équipe (membres du magasin) -- cochée d'office pour l'administrateur
    // (voir RoleMagasin::permissionsParDefaut()). Gérer l'équipe ne fait
    // jamais obtenir une autorisation qu'on n'a pas : seul le propriétaire
    // coche les autorisations d'un membre et change son rôle (voir
    // CreerMembreMagasinAction, MettreAJourMembreMagasinAction et
    // ReinitialiserMotDePasseMembreAction).
    public const EQUIPE_VIEW = 'equipe.view';

    public const EQUIPE_CREER = 'equipe.creer';

    public const EQUIPE_MODIFIER = 'equipe.modifier';

    // Paiements -- ni modification ni suppression après coup (voir
    // ProtegeLesDonneesValidees et PaiementPolicy) : une erreur de saisie se
    // corrige par un nouveau paiement, jamais en réécrivant l'historique
    // d'encaissement.
    public const PAIEMENT_VIEW = 'paiement.view';

    public const PAIEMENT_CREER = 'paiement.creer';

    // Avoirs
    public const AVOIR_VIEW = 'avoir.view';

    public const AVOIR_CREER = 'avoir.creer';

    public const AVOIR_ENVOYER = 'avoir.envoyer';

    // Clôture de la journée du magasin (e-reporting agrégé B2C). Sans
    // cloture_caisse.view_toutes, un membre ne voit que les clôtures qu'il
    // a lui-même déclenchées (voir cree_par et ClotureCaissePolicy) --
    // même confidentialité que facture.view_toutes.
    public const CLOTURE_CAISSE_VIEW = 'cloture_caisse.view';

    public const CLOTURE_CAISSE_VIEW_TOUTES = 'cloture_caisse.view_toutes';

    public const CLOTURE_CAISSE_GERER = 'cloture_caisse.gerer';

    // Achats (factures d'achat + avoirs fournisseur). Visibilité d'équipe
    // complète par défaut, sans confidentialité par créateur : ce sont des
    // documents de coût internes, pas des documents de vente attribués à
    // un vendeur (voir FournisseurResource/ProduitResource, qui n'ont pas
    // non plus cette restriction).
    public const FACTURE_ACHAT_VIEW = 'facture_achat.view';

    public const FACTURE_ACHAT_CREER = 'facture_achat.creer';

    public const FACTURE_ACHAT_VALIDER = 'facture_achat.valider';

    public const AVOIR_FOURNISSEUR_VIEW = 'avoir_fournisseur.view';

    public const AVOIR_FOURNISSEUR_CREER = 'avoir_fournisseur.creer';

    /**
     * Consulter la caisse sécurisée (registre fiscal signé, journal des
     * événements) et exporter le journal (voir EnregistrementFiscalPolicy) :
     * de quoi la montrer à l'administration fiscale.
     */
    public const CAISSE_SECURISEE_CONSULTER = 'caisse_securisee.consulter';

    /**
     * @return list<string>
     */
    public static function toutes(): array
    {
        return [
            self::CLIENT_VIEW, self::CLIENT_CREATE, self::CLIENT_UPDATE, self::CLIENT_DELETE,
            self::FOURNISSEUR_VIEW, self::FOURNISSEUR_CREATE, self::FOURNISSEUR_UPDATE, self::FOURNISSEUR_DELETE,
            self::PRODUIT_VIEW, self::PRODUIT_CREATE, self::PRODUIT_UPDATE, self::PRODUIT_DELETE, self::PRODUIT_AJUSTER_STOCK, self::PRODUIT_VOIR_VALEUR_STOCK, self::PRIX_VENTE_REGLER,
            self::DEVIS_VIEW, self::DEVIS_CREATE, self::DEVIS_UPDATE, self::DEVIS_ENVOYER,
            self::FACTURE_VIEW, self::FACTURE_VIEW_TOUTES, self::FACTURE_CREATE, self::FACTURE_EMETTRE, self::FACTURE_ENVOYER, self::RELANCES_REGLER,
            self::PARAMETRES_GERER,
            self::EQUIPE_VIEW, self::EQUIPE_CREER, self::EQUIPE_MODIFIER,
            self::PAIEMENT_VIEW, self::PAIEMENT_CREER,
            self::AVOIR_VIEW, self::AVOIR_CREER, self::AVOIR_ENVOYER,
            self::CLOTURE_CAISSE_VIEW, self::CLOTURE_CAISSE_VIEW_TOUTES, self::CLOTURE_CAISSE_GERER,
            self::FACTURE_ACHAT_VIEW, self::FACTURE_ACHAT_CREER, self::FACTURE_ACHAT_VALIDER,
            self::AVOIR_FOURNISSEUR_VIEW, self::AVOIR_FOURNISSEUR_CREER,
            self::CAISSE_SECURISEE_CONSULTER,
        ];
    }

    /**
     * Les autorisations qui restent permises quand le magasin est suspendu
     * ou fermé (voir ControleDesDroits) : tout consulter, clôturer la
     * journée et gérer l'équipe, pour couper un accès. Une nouvelle
     * autorisation de consultation s'ajoute ici ; oubliée, elle est
     * refusée en lecture seule, ce qui reste sûr.
     *
     * @return list<string>
     */
    public static function permisesEnLectureSeule(): array
    {
        return [
            self::CLIENT_VIEW,
            self::FOURNISSEUR_VIEW,
            self::PRODUIT_VIEW, self::PRODUIT_VOIR_VALEUR_STOCK,
            self::DEVIS_VIEW,
            self::FACTURE_VIEW, self::FACTURE_VIEW_TOUTES,
            self::EQUIPE_VIEW, self::EQUIPE_CREER, self::EQUIPE_MODIFIER,
            self::PAIEMENT_VIEW,
            self::AVOIR_VIEW,
            self::CLOTURE_CAISSE_VIEW, self::CLOTURE_CAISSE_VIEW_TOUTES, self::CLOTURE_CAISSE_GERER,
            self::FACTURE_ACHAT_VIEW,
            self::AVOIR_FOURNISSEUR_VIEW,
            self::CAISSE_SECURISEE_CONSULTER,
        ];
    }

    /**
     * Les autorisations cochées pour un membre, refusées dès que l'une
     * d'elles est inconnue.
     *
     * @param  array<array-key, mixed>  $permissions
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function verifierConnues(array $permissions): array
    {
        foreach ($permissions as $permission) {
            if (! is_string($permission) || ! in_array($permission, self::toutes(), true)) {
                throw new InvalidArgumentException('Autorisation inconnue : '.(is_scalar($permission) ? $permission : get_debug_type($permission)));
            }
        }

        return array_values($permissions);
    }

    /**
     * Libellés français affichés dans la liste des autorisations d'un
     * membre (voir MembreEquipeResource) -- le propriétaire n'a jamais à
     * lire une chaîne technique comme "avoir.view".
     *
     * @return array<string, string>
     */
    public static function libelles(): array
    {
        return [
            self::CLIENT_VIEW => 'Clients : voir',
            self::CLIENT_CREATE => 'Clients : créer',
            self::CLIENT_UPDATE => 'Clients : modifier',
            self::CLIENT_DELETE => 'Clients : supprimer',
            self::FOURNISSEUR_VIEW => 'Fournisseurs : voir',
            self::FOURNISSEUR_CREATE => 'Fournisseurs : créer',
            self::FOURNISSEUR_UPDATE => 'Fournisseurs : modifier',
            self::FOURNISSEUR_DELETE => 'Fournisseurs : supprimer',
            self::PRODUIT_VIEW => 'Produits et services : voir',
            self::PRODUIT_CREATE => 'Produits et services : créer',
            self::PRODUIT_UPDATE => 'Produits et services : modifier',
            self::PRODUIT_DELETE => 'Produits : supprimer',
            self::PRODUIT_AJUSTER_STOCK => 'Produits : gérer le stock (alertes de stock bas)',
            self::PRODUIT_VOIR_VALEUR_STOCK => 'Produits : voir la valeur totale du stock',
            self::PRIX_VENTE_REGLER => 'Prix de vente : régler le barème automatique',
            self::DEVIS_VIEW => 'Devis : voir',
            self::DEVIS_CREATE => 'Devis : créer',
            self::DEVIS_UPDATE => 'Devis : modifier',
            self::DEVIS_ENVOYER => 'Devis : envoyer (numéroter, envoyer par e-mail)',
            self::FACTURE_VIEW => 'Factures : voir (les siennes)',
            self::FACTURE_VIEW_TOUTES => 'Factures : voir celles de toute l\'équipe',
            self::FACTURE_CREATE => 'Factures : créer',
            self::FACTURE_EMETTRE => 'Factures : émettre',
            self::FACTURE_ENVOYER => 'Factures : envoyer par e-mail et relancer',
            self::RELANCES_REGLER => 'Relances : régler les relances automatiques',
            self::PARAMETRES_GERER => 'Paramètres du magasin : identité et TVA',
            self::EQUIPE_VIEW => 'Équipe : voir',
            self::EQUIPE_CREER => 'Équipe : créer des comptes',
            self::EQUIPE_MODIFIER => 'Équipe : modifier',
            self::PAIEMENT_VIEW => 'Paiements : voir',
            self::PAIEMENT_CREER => 'Paiements : enregistrer',
            self::AVOIR_VIEW => 'Avoirs : voir',
            self::AVOIR_CREER => 'Avoirs : créer',
            self::AVOIR_ENVOYER => 'Avoirs : envoyer par e-mail',
            self::CLOTURE_CAISSE_VIEW => 'Clôture de caisse : voir (les siennes)',
            self::CLOTURE_CAISSE_VIEW_TOUTES => 'Clôture de caisse : voir celles de toute l\'équipe',
            self::CLOTURE_CAISSE_GERER => 'Clôture de caisse : clôturer manuellement',
            self::FACTURE_ACHAT_VIEW => 'Factures d\'achat : voir',
            self::FACTURE_ACHAT_CREER => 'Factures d\'achat : créer',
            self::FACTURE_ACHAT_VALIDER => 'Factures d\'achat : valider',
            self::AVOIR_FOURNISSEUR_VIEW => 'Avoirs fournisseur : voir',
            self::AVOIR_FOURNISSEUR_CREER => 'Avoirs fournisseur : créer',
            self::CAISSE_SECURISEE_CONSULTER => 'Caisse sécurisée : consulter le registre et exporter le journal des événements',
        ];
    }
}

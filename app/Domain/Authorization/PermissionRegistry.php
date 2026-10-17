<?php

namespace App\Domain\Authorization;

use InvalidArgumentException;

/**
 * Liste centrale des permissions de l'application. Aucune chaîne magique
 * ne doit être écrite ailleurs ("client.create", ...) : on référence
 * toujours ces constantes, pour que renommer/retrouver une permission
 * reste sûr (IDE, recherche, refactor). La base ne connaît qu'elles : la
 * migration du référentiel des autorisations de magasin les y inscrit, et
 * une case qui n'y figure pas est refusée par sa clé étrangère (voir
 * Affectation::cocher()).
 *
 * Ces autorisations valent magasin par magasin (voir Affectation et
 * User::autorisationsDans()) : cocher "client.create" pour un vendeur dans
 * le magasin A ne lui donne rien dans le magasin B. Sans aucune case, le
 * propriétaire de la société les a toutes dans chacun de ses magasins, un
 * responsable toutes dans son magasin, et un expert-comptable les seules
 * consultations dans chaque magasin réel de la société (voir
 * consultations()). Un vendeur ou un observateur a celles de son
 * affectation à ce magasin, cochées par le propriétaire ou un responsable
 * du magasin à partir du modèle de son rôle (voir
 * RoleMagasin::permissionsParDefaut()), jamais au-delà des autorisations
 * de celui qui coche ; un administrateur, celles que le propriétaire lui
 * a cochées pour tous les magasins ou pour chaque magasin de sa sélection
 * (voir modeleDeLAdministrateur()). Les autorisations de la société
 * elle-même (gérer ses magasins, nommer ses responsables, ses paramètres)
 * sont à part (voir AutorisationsSociete).
 */
final class PermissionRegistry
{
    // Clients
    public const CLIENT_VIEW = 'client.view';

    public const CLIENT_CREATE = 'client.create';

    public const CLIENT_UPDATE = 'client.update';

    /**
     * Archiver un client, et le restaurer (voir ClientPolicy) : une fiche
     * ne se supprime jamais.
     */
    public const CLIENT_ARCHIVER = 'client.archiver';

    // Fournisseurs
    public const FOURNISSEUR_VIEW = 'fournisseur.view';

    public const FOURNISSEUR_CREATE = 'fournisseur.create';

    public const FOURNISSEUR_UPDATE = 'fournisseur.update';

    /**
     * Archiver un fournisseur, et le restaurer (voir FournisseurPolicy).
     */
    public const FOURNISSEUR_ARCHIVER = 'fournisseur.archiver';

    // Produits -- et services (voir ServicePolicy) : tout le catalogue
    public const PRODUIT_VIEW = 'produit.view';

    public const PRODUIT_CREATE = 'produit.create';

    public const PRODUIT_UPDATE = 'produit.update';

    /**
     * Archiver un produit, et le restaurer (voir ProduitPolicy). Un service
     * ne s'archive pas : il se retire de la caisse (voir ServicePolicy).
     */
    public const PRODUIT_ARCHIVER = 'produit.archiver';

    /**
     * Gérer le stock du magasin : reçoit les alertes de stock bas (voir
     * NotifierLeStockSousLeSeuilDAlerte).
     */
    public const PRODUIT_AJUSTER_STOCK = 'produit.ajuster_stock';

    // Un vendeur voit le prix/stock de chaque produit (nécessaire pour
    // vendre), mais pas la valorisation globale du stock du magasin --
    // information sensible : aucun modèle de vendeur ou d'observateur ne
    // la coche d'office ; le propriétaire, un responsable et un
    // expert-comptable l'ont toujours (voir ProduitResource).
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

    // Sans cette permission, un utilisateur ne voit et n'encaisse que les
    // factures qu'il a lui-même créées, et son tableau de bord montre
    // « Mes ventes » (B16, voir FacturePolicy et Facture::scopeVisiblesPar()).
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
     * Régler les paramètres du magasin : ses coordonnées et ses références
     * d'acheteur (CoordonneesMagasin), le délai de paiement et les
     * mentions entre professionnels de ses factures (ParametresFactures).
     * L'identité légale et la TVA sont celles de la société, son nom, son
     * adresse et son SIRET ceux que la société gère, dans son
     * administration. Le propriétaire et les responsables du magasin
     * l'ont toujours ; aucun modèle ne la coche d'office, pas même celui
     * de l'administrateur : le propriétaire ou un responsable la coche
     * pour qui il veut. L'équipe du magasin se gère par son responsable et
     * le propriétaire (voir EquipeDuMagasinResource).
     */
    public const PARAMETRES_GERER = 'parametres.gerer';

    // Paiements -- ni modification ni suppression après coup (voir
    // ProtegeLesDonneesValidees et PaiementPolicy) : une erreur de saisie se
    // corrige par une contre-passation, puis par le bon paiement, jamais en
    // réécrivant l'historique d'encaissement.
    public const PAIEMENT_VIEW = 'paiement.view';

    public const PAIEMENT_CREER = 'paiement.creer';

    /**
     * Contre-passer un paiement saisi par erreur (voir
     * ContrePasserPaiementAction et PaiementPolicy::contrePasser()) : le
     * propriétaire et les responsables l'ont toujours ; cochable pour un
     * vendeur, sur les paiements qu'il voit.
     */
    public const PAIEMENT_CONTRE_PASSER = 'paiement.contre_passer';

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
            self::CLIENT_VIEW, self::CLIENT_CREATE, self::CLIENT_UPDATE, self::CLIENT_ARCHIVER,
            self::FOURNISSEUR_VIEW, self::FOURNISSEUR_CREATE, self::FOURNISSEUR_UPDATE, self::FOURNISSEUR_ARCHIVER,
            self::PRODUIT_VIEW, self::PRODUIT_CREATE, self::PRODUIT_UPDATE, self::PRODUIT_ARCHIVER, self::PRODUIT_AJUSTER_STOCK, self::PRODUIT_VOIR_VALEUR_STOCK, self::PRIX_VENTE_REGLER,
            self::DEVIS_VIEW, self::DEVIS_CREATE, self::DEVIS_UPDATE, self::DEVIS_ENVOYER,
            self::FACTURE_VIEW, self::FACTURE_VIEW_TOUTES, self::FACTURE_CREATE, self::FACTURE_EMETTRE, self::FACTURE_ENVOYER, self::RELANCES_REGLER,
            self::PARAMETRES_GERER,
            self::PAIEMENT_VIEW, self::PAIEMENT_CREER, self::PAIEMENT_CONTRE_PASSER,
            self::AVOIR_VIEW, self::AVOIR_CREER, self::AVOIR_ENVOYER,
            self::CLOTURE_CAISSE_VIEW, self::CLOTURE_CAISSE_VIEW_TOUTES, self::CLOTURE_CAISSE_GERER,
            self::FACTURE_ACHAT_VIEW, self::FACTURE_ACHAT_CREER, self::FACTURE_ACHAT_VALIDER,
            self::AVOIR_FOURNISSEUR_VIEW, self::AVOIR_FOURNISSEUR_CREER,
            self::CAISSE_SECURISEE_CONSULTER,
        ];
    }

    /**
     * Les autorisations qui ne font que lire : les fiches, les documents,
     * les paiements, les achats, les clôtures, la caisse sécurisée (son
     * registre, son journal des événements, leur export et le contrôle
     * d'intégrité) et les téléchargements. Ce sont toutes les autorisations
     * d'un expert-comptable dans chaque magasin réel de sa société (voir
     * User::autorisationsDans()), et celles qui restent permises dans un
     * magasin en lecture seule (voir permisesEnLectureSeule()). Une
     * nouvelle autorisation de consultation s'ajoute ici ; oubliée, elle
     * manque à l'expert-comptable et est refusée en lecture seule, ce qui
     * reste sûr.
     *
     * @return list<string>
     */
    public static function consultations(): array
    {
        return [
            self::CLIENT_VIEW,
            self::FOURNISSEUR_VIEW,
            self::PRODUIT_VIEW, self::PRODUIT_VOIR_VALEUR_STOCK,
            self::DEVIS_VIEW,
            self::FACTURE_VIEW, self::FACTURE_VIEW_TOUTES,
            self::PAIEMENT_VIEW,
            self::AVOIR_VIEW,
            self::CLOTURE_CAISSE_VIEW, self::CLOTURE_CAISSE_VIEW_TOUTES,
            self::FACTURE_ACHAT_VIEW,
            self::AVOIR_FOURNISSEUR_VIEW,
            self::CAISSE_SECURISEE_CONSULTER,
        ];
    }

    /**
     * Les autorisations qui restent permises quand le magasin est suspendu
     * ou fermé (voir ControleDesDroits) : tout consulter, et clôturer la
     * journée, car les clôtures continuent jusqu'au bout. L'équipe reste
     * gérable, par le propriétaire et les responsables (voir
     * ControleDesDroits), et dans l'administration de la société (voir
     * AutorisationsSociete::permisesEnLectureSeule()).
     *
     * @return list<string>
     */
    public static function permisesEnLectureSeule(): array
    {
        return [...self::consultations(), self::CLOTURE_CAISSE_GERER];
    }

    /**
     * Le modèle des autorisations de magasin d'un nouvel administrateur,
     * que le propriétaire décoche à son gré (voir
     * CreerAdministrateurAction) : toutes, sauf les paramètres du magasin,
     * qu'il coche pour qui il veut.
     *
     * @return list<string>
     */
    public static function modeleDeLAdministrateur(): array
    {
        return array_values(array_diff(self::toutes(), [self::PARAMETRES_GERER]));
    }

    /**
     * Les autorisations des opérations commerciales, refusées tant que le
     * magasin ne peut pas vendre (voir ControleDesDroits et
     * Magasin::peutVendre()) : émettre une facture ou un ticket, envoyer
     * une facture, un devis ou un avoir, créer un avoir, enregistrer ou
     * contre-passer un paiement.
     *
     * @return list<string>
     */
    public static function reserveesAuxOperationsCommerciales(): array
    {
        return [
            self::FACTURE_EMETTRE,
            self::FACTURE_ENVOYER,
            self::DEVIS_ENVOYER,
            self::AVOIR_CREER,
            self::AVOIR_ENVOYER,
            self::PAIEMENT_CREER,
            self::PAIEMENT_CONTRE_PASSER,
        ];
    }

    /**
     * Les autorisations cochées pour une affectation, refusées dès que
     * l'une d'elles est inconnue, sans doublon, dans l'ordre alphabétique.
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

        $connues = array_values(array_unique($permissions));
        sort($connues);

        return $connues;
    }

    /**
     * Libellés français affichés dans la liste des autorisations d'un
     * vendeur, d'un observateur ou d'un administrateur (voir
     * EquipeDuMagasinResource et MembreResource) -- personne n'a jamais à
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
            self::CLIENT_ARCHIVER => 'Clients : archiver et restaurer',
            self::FOURNISSEUR_VIEW => 'Fournisseurs : voir',
            self::FOURNISSEUR_CREATE => 'Fournisseurs : créer',
            self::FOURNISSEUR_UPDATE => 'Fournisseurs : modifier',
            self::FOURNISSEUR_ARCHIVER => 'Fournisseurs : archiver et restaurer',
            self::PRODUIT_VIEW => 'Produits et services : voir',
            self::PRODUIT_CREATE => 'Produits et services : créer',
            self::PRODUIT_UPDATE => 'Produits et services : modifier',
            self::PRODUIT_ARCHIVER => 'Produits : archiver et restaurer',
            self::PRODUIT_AJUSTER_STOCK => 'Produits : gérer le stock (alertes de stock bas)',
            self::PRODUIT_VOIR_VALEUR_STOCK => 'Produits : voir la valeur totale du stock',
            self::PRIX_VENTE_REGLER => 'Prix de vente : régler le barème automatique',
            self::DEVIS_VIEW => 'Devis : voir',
            self::DEVIS_CREATE => 'Devis : créer',
            self::DEVIS_UPDATE => 'Devis : modifier, et supprimer un brouillon jamais envoyé',
            self::DEVIS_ENVOYER => 'Devis : envoyer (numéroter, envoyer par e-mail)',
            self::FACTURE_VIEW => 'Factures : voir (les siennes)',
            self::FACTURE_VIEW_TOUTES => 'Factures : voir celles de toute l\'équipe',
            self::FACTURE_CREATE => 'Factures : créer',
            self::FACTURE_EMETTRE => 'Factures : émettre',
            self::FACTURE_ENVOYER => 'Factures : envoyer par e-mail et relancer',
            self::RELANCES_REGLER => 'Relances : régler les relances automatiques',
            self::PARAMETRES_GERER => 'Paramètres du magasin : coordonnées, références d\'acheteur, échéance et mentions des factures',
            self::PAIEMENT_VIEW => 'Paiements : voir',
            self::PAIEMENT_CREER => 'Paiements : enregistrer',
            self::PAIEMENT_CONTRE_PASSER => 'Paiements : contre-passer une saisie erronée',
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

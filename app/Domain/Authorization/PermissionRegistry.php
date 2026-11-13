<?php

namespace App\Domain\Authorization;

use App\Domain\Tenancy\Enums\TypeDActivite;
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
 * propriétaire de la société les a toutes sauf celles de la vente (voir
 * deVente() et duProprietaire()) dans chacun de ses magasins réels : il
 * supervise et contrôle, et n'y vend jamais ; dans le magasin de
 * démonstration de sa société, tant qu'il est ouvert, il les a toutes,
 * celles de la vente comprises, et s'y entraîne à tout (voir
 * duProprietaireEnDemonstration()) ; un responsable les a toutes dans son
 * magasin, et un compte du cabinet d'expertise comptable lié celles que le
 * client accorde au cabinet dans chacun de ses magasins réels (voir
 * parDefautDuCabinet() et ajoutablesAuCabinet()).
 * Un vendeur ou un observateur a celles de son
 * affectation à ce magasin, cochées par le propriétaire ou un responsable
 * du magasin à partir du modèle de son rôle (voir
 * RoleMagasin::permissionsParDefaut()) : le propriétaire peut toutes les
 * cocher, celles de la vente comprises, un responsable jamais au-delà des
 * siennes ; un administrateur, celles que le propriétaire lui a cochées
 * pour tous les magasins ou pour chaque magasin de sa sélection (voir
 * modeleDeLAdministrateur()), celles de la vente seulement s'il les lui
 * coche. Chaque autorisation est rangée, en toutes lettres, parmi celles
 * de la vente ou celles qui n'en sont pas (voir horsVente()), et dans une
 * seule famille : celle des articles (voir articles()), qui n'existe que
 * dans les magasins d'une société qui a des articles (voir
 * TypeDActivite::aDesArticles() et pourLeType()), ou la famille commune,
 * toutes les autres ; le référentiel en base porte la famille de chacune.
 * Dans un magasin, les autorisations de tout compte, les modèles qui les
 * précochent et les cases proposées se croisent avec celles du type
 * d'activité de sa société. Le téléchargement des documents par période
 * (voir cocheesParLeProprietaire()) fait exception : le propriétaire
 * l'a toujours, tout autre compte seulement si le propriétaire le lui
 * coche, responsable compris, et un client le coche pour son cabinet lié. Les autorisations de la société elle-même
 * (gérer ses magasins, nommer ses responsables, ses paramètres) sont à
 * part (voir AutorisationsSociete).
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

    // Produits, les articles achetés et suivis en stock (voir ProduitPolicy)
    public const PRODUIT_VIEW = 'produit.view';

    public const PRODUIT_CREATE = 'produit.create';

    public const PRODUIT_UPDATE = 'produit.update';

    /**
     * Archiver un produit, et le restaurer (voir ProduitPolicy). Un service
     * ne s'archive pas : il se retire de la caisse (voir ServicePolicy).
     */
    public const PRODUIT_ARCHIVER = 'produit.archiver';

    // Services, les prestations du magasin (voir ServicePolicy), dans tous
    // les types qui ont un magasin
    public const SERVICE_VIEW = 'service.view';

    public const SERVICE_CREATE = 'service.create';

    public const SERVICE_UPDATE = 'service.update';

    /**
     * Gérer le stock du magasin : reçoit les alertes de stock bas ou
     * négatif (voir NotifierLeStockSousLeSeuilDAlerte et
     * NotifierLeStockNegatif).
     */
    public const PRODUIT_AJUSTER_STOCK = 'produit.ajuster_stock';

    /**
     * Vendre au-delà du stock disponible (R1, voir VerificateurDeStock) :
     * après un avertissement, qui se renouvelle si le stock a encore
     * baissé. Une autorisation de vente (voir deVente()) : les responsables
     * du magasin l'ont toujours, le propriétaire seulement dans le magasin
     * de démonstration, le seul où il vend ; aucun modèle ne la coche
     * d'office, pas même celui de l'administrateur (voir
     * modeleDeLAdministrateur()), et un cabinet lié ne l'a jamais (voir
     * ajoutablesAuCabinet()).
     */
    public const STOCK_VENDRE_SANS_STOCK = 'stock.vendre_sans_stock';

    // Un vendeur voit le prix/stock de chaque produit (nécessaire pour
    // vendre), mais pas la valorisation globale du stock du magasin --
    // information sensible : aucun modèle de vendeur ou d'observateur ne
    // la coche d'office ; le propriétaire et un responsable l'ont
    // toujours, un cabinet lié par défaut (voir ProduitResource et
    // parDefautDuCabinet()).
    public const PRODUIT_VOIR_VALEUR_STOCK = 'produit.voir_valeur_stock';

    /**
     * Régler le barème qui calcule les prix de vente (voir
     * BaremePrixDeVente) : il fixe les prix de tout le magasin.
     */
    public const PRIX_VENTE_REGLER = 'prix_vente.regler';

    /**
     * Accorder une remise, fixer le prix d'un service (son prix de
     * référence au catalogue comme celui d'une ligne) et mettre sur une
     * ligne de produit un prix différent de sa fiche (R2, voir
     * DerogationsDePrix). Pas une autorisation de vente : elle ne vaut sur
     * une ligne de ticket ou de facture que pour qui peut aussi la créer.
     * Les responsables du magasin et le propriétaire l'ont toujours (le
     * propriétaire, qui ne vend que dans le magasin de démonstration, pour
     * le prix de référence d'un service et les lignes d'un devis, et, dans
     * ce seul magasin, celles d'un ticket ou d'une facture) ; le modèle de
     * l'administrateur la précoche, aucun modèle de vendeur ou
     * d'observateur ; un cabinet lié ne l'a jamais.
     */
    public const VENTE_REMISES_ET_PRIX = 'vente.remises_et_prix';

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
     * Noter les preuves de l'exonération d'une facture et le n° du
     * bordereau de détaxe d'un ticket (voir FacturePolicy::justifier(),
     * AjouterPreuveOperationAction et NoterBordereauDetaxeAction) : une
     * information ajoutée après l'émission, hors du document signé, et non
     * une vente. Le propriétaire et les responsables du magasin l'ont
     * toujours ; les modèles du vendeur et de l'administrateur la
     * précochent, pas celui de l'observateur, à qui le propriétaire ou un
     * responsable du magasin peut la cocher ; un cabinet lié ne l'a jamais
     * (voir ajoutablesAuCabinet()).
     */
    public const FACTURE_JUSTIFIER = 'facture.justifier';

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
     * Télécharger les documents par période (décision de l'éditeur du
     * 03/11/2026, lot EX ; voir DocumentsDeLaPeriode) : la page
     * « Télécharger les documents » du magasin, et ce magasin dans celles
     * de l'espace de la société et de l'espace du cabinet. Seul le
     * propriétaire la coche (voir cocheesParLeProprietaire()) ; elle ne
     * donne que les documents que les autres autorisations laissent voir.
     */
    public const DOCUMENTS_TELECHARGER = 'documents.telecharger';

    /**
     * La famille des articles (voir articles()) et la famille commune, telles
     * que le référentiel en base les porte.
     */
    public const FAMILLE_ARTICLES = 'articles';

    public const FAMILLE_COMMUNE = 'commune';

    /**
     * @return list<string>
     */
    public static function toutes(): array
    {
        return [
            self::CLIENT_VIEW, self::CLIENT_CREATE, self::CLIENT_UPDATE, self::CLIENT_ARCHIVER,
            self::FOURNISSEUR_VIEW, self::FOURNISSEUR_CREATE, self::FOURNISSEUR_UPDATE, self::FOURNISSEUR_ARCHIVER,
            self::PRODUIT_VIEW, self::PRODUIT_CREATE, self::PRODUIT_UPDATE, self::PRODUIT_ARCHIVER, self::PRODUIT_AJUSTER_STOCK, self::PRODUIT_VOIR_VALEUR_STOCK, self::PRIX_VENTE_REGLER,
            self::SERVICE_VIEW, self::SERVICE_CREATE, self::SERVICE_UPDATE,
            self::STOCK_VENDRE_SANS_STOCK, self::VENTE_REMISES_ET_PRIX,
            self::DEVIS_VIEW, self::DEVIS_CREATE, self::DEVIS_UPDATE, self::DEVIS_ENVOYER,
            self::FACTURE_VIEW, self::FACTURE_VIEW_TOUTES, self::FACTURE_CREATE, self::FACTURE_EMETTRE, self::FACTURE_ENVOYER, self::FACTURE_JUSTIFIER, self::RELANCES_REGLER,
            self::PARAMETRES_GERER,
            self::PAIEMENT_VIEW, self::PAIEMENT_CREER, self::PAIEMENT_CONTRE_PASSER,
            self::AVOIR_VIEW, self::AVOIR_CREER, self::AVOIR_ENVOYER,
            self::CLOTURE_CAISSE_VIEW, self::CLOTURE_CAISSE_VIEW_TOUTES, self::CLOTURE_CAISSE_GERER,
            self::FACTURE_ACHAT_VIEW, self::FACTURE_ACHAT_CREER, self::FACTURE_ACHAT_VALIDER,
            self::AVOIR_FOURNISSEUR_VIEW, self::AVOIR_FOURNISSEUR_CREER,
            self::CAISSE_SECURISEE_CONSULTER,
            self::DOCUMENTS_TELECHARGER,
        ];
    }

    /**
     * Les autorisations qui ne font que lire : les fiches, les documents,
     * les paiements, les achats, les clôtures, la caisse sécurisée (son
     * registre, son journal des événements, leur export et le contrôle
     * d'intégrité) et les téléchargements. Ce sont celles qu'un client
     * accorde par défaut au cabinet d'expertise comptable qu'il lie (voir
     * parDefautDuCabinet()), et celles qui restent permises dans un
     * magasin en lecture seule (voir permisesEnLectureSeule()). Une
     * nouvelle autorisation de consultation s'ajoute ici ; oubliée, elle
     * manque au cabinet lié et est refusée en lecture seule, ce qui reste
     * sûr. Le téléchargement des documents par période n'en est pas : il ne
     * s'accorde jamais par défaut (voir cocheesParLeProprietaire()).
     *
     * @return list<literal-string>
     */
    public static function consultations(): array
    {
        return [
            self::CLIENT_VIEW,
            self::FOURNISSEUR_VIEW,
            self::PRODUIT_VIEW, self::PRODUIT_VOIR_VALEUR_STOCK,
            self::SERVICE_VIEW,
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
     * ou fermé (voir ControleDesDroits) : tout consulter et télécharger,
     * documents par période compris ; clôturer la journée, car les
     * clôtures continuent jusqu'au bout ; noter les preuves d'exonération,
     * le bordereau de détaxe et son visa, qui arrivent souvent après la
     * fermeture (décision de l'éditeur du 01/11/2026, Q-AF12). L'équipe
     * reste gérable, par le propriétaire et les responsables (voir
     * ControleDesDroits), et dans l'administration de la société (voir
     * AutorisationsSociete::permisesEnLectureSeule()).
     *
     * @return list<string>
     */
    public static function permisesEnLectureSeule(): array
    {
        return [...self::consultations(), self::DOCUMENTS_TELECHARGER, self::CLOTURE_CAISSE_GERER, self::FACTURE_JUSTIFIER];
    }

    /**
     * Les autorisations de la vente : créer et émettre un ticket ou une
     * facture, enregistrer un encaissement (les parts d'un règlement et le
     * bon d'avoir compris, à la caisse comme sur un ticket impayé), vendre
     * sans stock. Le propriétaire ne les a dans aucun magasin réel (voir
     * duProprietaire()), seulement dans le magasin de démonstration de sa
     * société (voir duProprietaireEnDemonstration()) ; un administrateur
     * seulement quand le propriétaire les lui coche, jamais précochées
     * (voir modeleDeLAdministrateur()). Ni l'avoir, ni le devis, ni la
     * contre-passation, ni l'envoi, ni la clôture ne sont des ventes (voir
     * horsVente()).
     *
     * @return list<string>
     */
    public static function deVente(): array
    {
        return [
            self::FACTURE_CREATE,
            self::FACTURE_EMETTRE,
            self::PAIEMENT_CREER,
            self::STOCK_VENDRE_SANS_STOCK,
        ];
    }

    /**
     * Toutes les autres autorisations, écrites en toutes lettres : une
     * autorisation nouvelle doit être rangée ici ou parmi celles de la
     * vente (voir deVente()), et un test refuse le registre tant qu'elle
     * ne l'est pas.
     *
     * @return list<string>
     */
    public static function horsVente(): array
    {
        return [
            self::CLIENT_VIEW, self::CLIENT_CREATE, self::CLIENT_UPDATE, self::CLIENT_ARCHIVER,
            self::FOURNISSEUR_VIEW, self::FOURNISSEUR_CREATE, self::FOURNISSEUR_UPDATE, self::FOURNISSEUR_ARCHIVER,
            self::PRODUIT_VIEW, self::PRODUIT_CREATE, self::PRODUIT_UPDATE, self::PRODUIT_ARCHIVER, self::PRODUIT_AJUSTER_STOCK, self::PRODUIT_VOIR_VALEUR_STOCK, self::PRIX_VENTE_REGLER,
            self::SERVICE_VIEW, self::SERVICE_CREATE, self::SERVICE_UPDATE,
            self::VENTE_REMISES_ET_PRIX,
            self::DEVIS_VIEW, self::DEVIS_CREATE, self::DEVIS_UPDATE, self::DEVIS_ENVOYER,
            self::FACTURE_VIEW, self::FACTURE_VIEW_TOUTES, self::FACTURE_ENVOYER, self::FACTURE_JUSTIFIER, self::RELANCES_REGLER,
            self::PARAMETRES_GERER,
            self::PAIEMENT_VIEW, self::PAIEMENT_CONTRE_PASSER,
            self::AVOIR_VIEW, self::AVOIR_CREER, self::AVOIR_ENVOYER,
            self::CLOTURE_CAISSE_VIEW, self::CLOTURE_CAISSE_VIEW_TOUTES, self::CLOTURE_CAISSE_GERER,
            self::FACTURE_ACHAT_VIEW, self::FACTURE_ACHAT_CREER, self::FACTURE_ACHAT_VALIDER,
            self::AVOIR_FOURNISSEUR_VIEW, self::AVOIR_FOURNISSEUR_CREER,
            self::CAISSE_SECURISEE_CONSULTER,
            self::DOCUMENTS_TELECHARGER,
        ];
    }

    /**
     * La famille des articles, en toutes lettres : les fournisseurs, les
     * produits et leur stock, le barème des prix de vente, la vente sans
     * stock, les achats et les avoirs fournisseur, et les preuves d'export
     * et de livraison et le bordereau de détaxe, qui ne portent que sur des
     * biens. Elles n'existent que dans les magasins d'une société qui a
     * des articles (voir pourLeType()) : ni proposées, ni précochées, ni
     * lues ailleurs, et la base refuse de les cocher (voir
     * GardesDesTypesDActivite). Toutes les autres forment la famille
     * commune.
     *
     * @return list<string>
     */
    public static function articles(): array
    {
        return [
            self::FOURNISSEUR_VIEW, self::FOURNISSEUR_CREATE, self::FOURNISSEUR_UPDATE, self::FOURNISSEUR_ARCHIVER,
            self::PRODUIT_VIEW, self::PRODUIT_CREATE, self::PRODUIT_UPDATE, self::PRODUIT_ARCHIVER, self::PRODUIT_AJUSTER_STOCK, self::PRODUIT_VOIR_VALEUR_STOCK,
            self::PRIX_VENTE_REGLER,
            self::STOCK_VENDRE_SANS_STOCK,
            self::FACTURE_ACHAT_VIEW, self::FACTURE_ACHAT_CREER, self::FACTURE_ACHAT_VALIDER,
            self::AVOIR_FOURNISSEUR_VIEW, self::AVOIR_FOURNISSEUR_CREER,
            self::FACTURE_JUSTIFIER,
        ];
    }

    /**
     * Les autorisations qui existent dans les magasins d'une société de ce
     * type, dans l'ordre du registre : toutes pour une société qui a des
     * articles, toutes sauf celles des articles (voir articles()) pour une
     * société de services, aucune pour un cabinet d'expertise comptable,
     * qui n'a aucun magasin.
     *
     * @return list<string>
     */
    public static function pourLeType(TypeDActivite $type): array
    {
        return match (true) {
            ! $type->aDesMagasins() => [],
            $type->aDesArticles() => self::toutes(),
            default => array_values(array_diff(self::toutes(), self::articles())),
        };
    }

    /**
     * Les autorisations du propriétaire dans chacun des magasins réels de
     * sa société, sans case : toutes celles du type de la société (voir
     * pourLeType()), sauf celles de la vente (voir deVente()), dans l'ordre
     * alphabétique. Il supervise et contrôle : il fait les avoirs et les
     * devis, contre-passe, envoie et relance, clôture la journée et ferme
     * la caisse d'un vendeur, mais ne crée, n'émet ni n'encaisse jamais
     * aucune vente dans un magasin réel, et la base le refuse (voir
     * GardesDeLaVente). Le magasin de démonstration fait seule exception
     * (voir duProprietaireEnDemonstration()).
     *
     * @return list<string>
     */
    public static function duProprietaire(TypeDActivite $type): array
    {
        $autorisations = array_values(array_diff(self::pourLeType($type), self::deVente()));
        sort($autorisations);

        return $autorisations;
    }

    /**
     * Les autorisations du propriétaire dans le magasin de démonstration
     * de sa société, tant qu'il est ouvert, sans case : toutes celles du
     * type de la société (voir pourLeType()), celles de la vente comprises,
     * dans l'ordre alphabétique (décision de l'éditeur du 12/11/2026). Il
     * s'y entraîne à tout, comme un responsable : il ouvre la caisse, crée
     * et émet des tickets et des factures, encaisse, vend sans stock,
     * accorde des remises, utilise un bon d'avoir, ferme sa caisse et
     * clôture la journée. Fermée, à la validation de la société ou faute
     * d'opération, la démonstration est en lecture seule et ne vend plus,
     * pour lui comme pour tout compte (voir ControleDesDroits et
     * Magasin::peutVendre()), et il n'y a plus que celles de
     * duProprietaire() : il n'y tient plus de caisse, sinon pour fermer
     * celle qu'il y aurait laissée ouverte (voir
     * User::autorisationsEnregistreesDans() et
     * ClotureCaissePersonnellePolicy::fermer()).
     *
     * @return list<string>
     */
    public static function duProprietaireEnDemonstration(TypeDActivite $type): array
    {
        $autorisations = self::pourLeType($type);
        sort($autorisations);

        return $autorisations;
    }

    /**
     * Les autorisations que seul le propriétaire coche, compte par compte,
     * dans un magasin d'une société de ce type : le téléchargement des
     * documents par période (décision de l'éditeur du 03/11/2026, E3). Le
     * propriétaire l'a toujours ; aucun modèle ne la précoche, pas même
     * celui de l'administrateur ; un responsable, qui a sans case toutes
     * les autres autorisations de son magasin, ne l'a que cochée par le
     * propriétaire, et ne la coche jamais pour un vendeur ou un
     * observateur, dont la case cochée par le propriétaire reste telle
     * quelle quand le responsable change ses autres cases (voir
     * AffectationsDesMembres::cases()) ; le cabinet lié ne l'a que cochée
     * par le propriétaire du client, jamais par défaut (voir duCabinet()).
     *
     * @return list<literal-string>
     */
    public static function cocheesParLeProprietaire(TypeDActivite $type): array
    {
        return array_values(array_intersect([self::DOCUMENTS_TELECHARGER], self::pourLeType($type)));
    }

    /**
     * Le modèle des autorisations de magasin d'un nouvel administrateur,
     * que le propriétaire décoche à son gré (voir
     * CreerAdministrateurAction) : toutes celles du type de la société
     * (voir pourLeType()), sauf les paramètres du magasin, les
     * autorisations de vente (voir deVente()) et celles que le
     * propriétaire coche toujours lui-même (voir
     * cocheesParLeProprietaire()), qu'il coche pour qui il veut.
     *
     * @return list<string>
     */
    public static function modeleDeLAdministrateur(TypeDActivite $type): array
    {
        return array_values(array_diff(self::pourLeType($type), [self::PARAMETRES_GERER], self::deVente(), self::cocheesParLeProprietaire($type)));
    }

    /**
     * Les autorisations qu'un client accorde par défaut au cabinet
     * d'expertise comptable qu'il lie : les consultations (voir
     * consultations()) qui existent dans les magasins de son type (voir
     * pourLeType()), dans l'ordre alphabétique.
     *
     * @return list<literal-string>
     */
    public static function parDefautDuCabinet(TypeDActivite $typeDuClient): array
    {
        $autorisations = array_values(array_intersect(self::consultations(), self::pourLeType($typeDuClient)));
        sort($autorisations);

        return $autorisations;
    }

    /**
     * Les seules autorisations qu'un client peut ajouter à celles de son
     * cabinet lié, écrites en toutes lettres : celles des achats et du
     * stock, qui n'existent que dans une société qui a des articles (voir
     * pourLeType()). Créer des produits y figure parce que valider une
     * facture d'achat ou un inventaire d'ouverture l'exige ; jamais la
     * vente, les clients, les services, les devis, les avoirs, les envois,
     * les paramètres ni la caisse.
     *
     * @return list<literal-string>
     */
    public static function ajoutablesAuCabinet(TypeDActivite $typeDuClient): array
    {
        return array_values(array_intersect([
            self::FOURNISSEUR_CREATE,
            self::FOURNISSEUR_UPDATE,
            self::FOURNISSEUR_ARCHIVER,
            self::FACTURE_ACHAT_CREER,
            self::FACTURE_ACHAT_VALIDER,
            self::AVOIR_FOURNISSEUR_CREER,
            self::PRODUIT_CREATE,
        ], self::pourLeType($typeDuClient)));
    }

    /**
     * Toutes les autorisations qu'un client de ce type peut accorder à son
     * cabinet lié (voir parDefautDuCabinet() et ajoutablesAuCabinet()), et
     * le téléchargement des documents par période, qu'il ne lui accorde
     * jamais par défaut (voir cocheesParLeProprietaire()), dans l'ordre
     * alphabétique ; aucune pour un cabinet, qui n'est jamais client. La liste d'un client qui a des articles est celle que la
     * base écrit en toutes lettres (voir GardesDesCabinets).
     *
     * @return list<literal-string>
     */
    public static function duCabinet(TypeDActivite $typeDuClient): array
    {
        $autorisations = array_values(array_unique([...self::parDefautDuCabinet($typeDuClient), ...self::ajoutablesAuCabinet($typeDuClient), ...self::cocheesParLeProprietaire($typeDuClient)]));
        sort($autorisations);

        return $autorisations;
    }

    /**
     * Les paires d'autorisations qu'un client accorde ou retire toujours
     * ensemble à son cabinet lié, sous une seule case : « voir (les
     * siennes) » et « voir celles de toute l'équipe », des factures et des
     * clôtures de caisse. Un compte d'un cabinet ne fait jamais ni facture
     * ni clôture : « les siennes » ne désigne rien pour lui, qui voit
     * toutes celles du magasin ou aucune (décision de l'éditeur du
     * 01/11/2026) ; la base refuse une paire incomplète (voir
     * GardesDesCabinets::poserLesPaires()). Rien ne change pour les comptes
     * de la société, pour qui « les siennes » garde son sens.
     *
     * @var array<literal-string, literal-string>
     */
    public const PAIRES_DU_CABINET = [
        self::FACTURE_VIEW => self::FACTURE_VIEW_TOUTES,
        self::CLOTURE_CAISSE_VIEW => self::CLOTURE_CAISSE_VIEW_TOUTES,
    ];

    /**
     * Les autorisations accordées au cabinet lié d'un client de ce type,
     * refusées dès que l'une d'elles ne peut pas lui être accordée (voir
     * duCabinet()), ou que « voir celles de toute l'équipe » vient sans
     * « voir » ; « voir » les factures ou les clôtures accorde aussi celles
     * de toute l'équipe (voir PAIRES_DU_CABINET) ; sans doublon, dans
     * l'ordre alphabétique.
     *
     * @param  array<array-key, mixed>  $autorisations
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function verifierDuCabinet(array $autorisations, TypeDActivite $typeDuClient): array
    {
        $permises = self::duCabinet($typeDuClient);

        foreach ($autorisations as $autorisation) {
            if (! is_string($autorisation) || ! in_array($autorisation, $permises, true)) {
                throw new InvalidArgumentException('Autorisation qui ne s\'accorde pas à un cabinet : '.(is_string($autorisation) ? (self::libelles()[$autorisation] ?? $autorisation) : get_debug_type($autorisation)));
            }
        }

        $connues = array_values(array_unique($autorisations));

        foreach (self::PAIRES_DU_CABINET as $voir => $toutes) {
            if (in_array($toutes, $connues, true) && ! in_array($voir, $connues, true)) {
                throw new InvalidArgumentException('Autorisation qui ne s\'accorde pas seule à un cabinet : '.self::libelles()[$toutes].' (cochez « '.self::libellesDuCabinet($typeDuClient)[$voir].' »)');
            }

            if (in_array($voir, $connues, true) && ! in_array($toutes, $connues, true)) {
                $connues[] = $toutes;
            }
        }

        sort($connues);

        return $connues;
    }

    /**
     * Les libellés des cases des autorisations qu'un client de ce type peut
     * accorder à son cabinet lié, dans l'ordre du registre : une seule case
     * par paire de PAIRES_DU_CABINET, « voir toutes celles du magasin »,
     * et créer des produits précisé : seulement ceux d'une facture d'achat
     * ou d'un inventaire d'ouverture.
     *
     * @return array<string, string>
     */
    public static function libellesDuCabinet(TypeDActivite $typeDuClient): array
    {
        $libelles = array_diff_key(array_intersect_key(self::libelles(), array_flip(self::duCabinet($typeDuClient))), array_flip(self::PAIRES_DU_CABINET));
        $precises = [
            self::FACTURE_VIEW => 'Factures : voir toutes celles du magasin',
            self::CLOTURE_CAISSE_VIEW => 'Clôtures de caisse : voir toutes celles du magasin',
            self::PRODUIT_CREATE => 'Produits : créer ceux d\'une facture d\'achat ou d\'un inventaire d\'ouverture',
        ];

        return array_replace($libelles, array_intersect_key($precises, $libelles));
    }

    /**
     * Les libellés des cases de ces autorisations d'un cabinet lié, dans
     * l'ordre du registre, une seule par paire de PAIRES_DU_CABINET, sans
     * lire le type du client : les clés suffisent, chacune ayant le même
     * libellé dans tous les types où elle existe (voir libellesDuCabinet()).
     * Pour l'e-mail d'une invitation ou d'une demande, la page d'une
     * invitation et la fiche d'un client.
     *
     * @param  array<array-key, string>  $cles
     * @return list<string>
     */
    public static function libellesDe(array $cles): array
    {
        return array_values(array_intersect_key(self::libellesDuCabinet(TypeDActivite::Commerce), array_flip($cles)));
    }

    /**
     * Les cases de ces autorisations d'un cabinet, sans la seconde de
     * chaque paire de PAIRES_DU_CABINET, que la première accorde avec
     * elle : ce que les écrans cochent, dans l'ordre reçu.
     *
     * @param  list<string>  $autorisations
     * @return list<string>
     */
    public static function casesDuCabinet(array $autorisations): array
    {
        return array_values(array_diff($autorisations, self::PAIRES_DU_CABINET));
    }

    /**
     * Les autorisations des opérations commerciales, refusées tant que le
     * magasin ne peut pas vendre (voir ControleDesDroits et
     * Magasin::peutVendre()) : émettre une facture ou un ticket, envoyer
     * une facture, un devis ou un avoir, créer un avoir, enregistrer ou
     * contre-passer un paiement. Noter les preuves de l'exonération d'une
     * vente déjà émise, son bordereau de détaxe ou son visa n'en est pas :
     * cela continue jusqu'au bout (voir permisesEnLectureSeule()).
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
     * Les autorisations cochées pour une affectation dans un magasin d'une
     * société de ce type, refusées dès que l'une d'elles est inconnue ou
     * n'existe pas dans ce type (voir pourLeType()), sans doublon, dans
     * l'ordre alphabétique.
     *
     * @param  array<array-key, mixed>  $permissions
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function verifierConnues(array $permissions, TypeDActivite $type): array
    {
        $duType = self::pourLeType($type);

        foreach ($permissions as $permission) {
            if (! is_string($permission) || ! in_array($permission, self::toutes(), true)) {
                throw new InvalidArgumentException('Autorisation inconnue : '.(is_scalar($permission) ? $permission : get_debug_type($permission)));
            }

            if (! in_array($permission, $duType, true)) {
                throw new InvalidArgumentException('Autorisation qui n\'existe pas pour une société « '.$type->getLabel().' » : '.(self::libelles()[$permission] ?? $permission));
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
            self::PRODUIT_VIEW => 'Produits : voir',
            self::PRODUIT_CREATE => 'Produits : créer',
            self::PRODUIT_UPDATE => 'Produits : modifier',
            self::PRODUIT_ARCHIVER => 'Produits : archiver et restaurer',
            self::PRODUIT_AJUSTER_STOCK => 'Produits : gérer le stock (alertes de stock bas ou négatif)',
            self::PRODUIT_VOIR_VALEUR_STOCK => 'Produits : voir la valeur totale du stock',
            self::PRIX_VENTE_REGLER => 'Prix de vente : régler le barème automatique',
            self::SERVICE_VIEW => 'Services : voir',
            self::SERVICE_CREATE => 'Services : créer',
            self::SERVICE_UPDATE => 'Services : modifier',
            self::STOCK_VENDRE_SANS_STOCK => 'Stock : vendre sans stock, après un avertissement',
            self::VENTE_REMISES_ET_PRIX => 'Ventes : accorder des remises et fixer le prix d\'un service',
            self::DEVIS_VIEW => 'Devis : voir',
            self::DEVIS_CREATE => 'Devis : créer',
            self::DEVIS_UPDATE => 'Devis : modifier, et supprimer un brouillon jamais envoyé',
            self::DEVIS_ENVOYER => 'Devis : envoyer (numéroter, envoyer par e-mail)',
            self::FACTURE_VIEW => 'Factures : voir (les siennes)',
            self::FACTURE_VIEW_TOUTES => 'Factures : voir celles de toute l\'équipe',
            self::FACTURE_CREATE => 'Factures : créer',
            self::FACTURE_EMETTRE => 'Factures : émettre',
            self::FACTURE_ENVOYER => 'Factures : envoyer par e-mail et relancer',
            self::FACTURE_JUSTIFIER => 'Factures : noter les preuves d\'exonération et les bordereaux de détaxe',
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
            self::DOCUMENTS_TELECHARGER => 'Documents : télécharger les documents par période (ZIP ou CSV)',
        ];
    }
}

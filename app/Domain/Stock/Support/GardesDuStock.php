<?php

namespace App\Domain\Stock\Support;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use Illuminate\Support\Facades\DB;

/**
 * Les gardes par lesquelles la base elle-même tient le grand livre du
 * stock et le stock de chaque produit, même face à une requête directe :
 *
 * - le grand livre (mouvements_stock) est en ajout seul (voir
 *   DeclencheursDeProtection::ajoutSeul()) ; chaque mouvement porte une
 *   quantité strictement positive, un type connu (TypeMouvementStock), qui
 *   seul dit son sens, et un coût unitaire positif ou nul, renseigné pour
 *   les seuls types qui le portent (TypeMouvementStock::porteSonCout()) ;
 * - le stock d'un produit est la somme signée de son grand livre, tenue par
 *   la base : chaque mouvement inséré l'ajoute aussitôt au produit de son
 *   magasin et y note qu'il est le dernier compté
 *   (produits.dernier_mouvement_stock_id) ; aucun autre changement du stock
 *   ni de ce dernier mouvement n'est accepté : seul le déclencheur du grand
 *   livre l'écrit (une requête directe sur produits est refusée, quelle
 *   que soit la valeur), vers un autre mouvement de ce produit et de son
 *   magasin, le nouveau stock étant l'ancien plus sa variation. Deux
 *   mouvements simultanés se comptent dans l'ordre où leurs transactions
 *   prennent la ligne du produit, qui n'est pas toujours celui de leurs
 *   identifiants : le dernier compté n'est donc pas forcément le plus
 *   grand ; un produit
 *   naît sans stock (voir poserLaNaissanceSansStock()) ;
 * - chaque mouvement est justifié par le document de son magasin que son
 *   type désigne (TypeMouvementStock::justificatif()), dans l'état voulu,
 *   qui porte son produit, dans la limite de ce document, à l'instant de
 *   son inscription (voir poserLaProvenance()), et au nom de qui a fait ce
 *   document (voir poserLAuteurDesMouvements()).
 *
 * Posées à la fin de la migration de création de la dernière table que
 * chacune lit, et retirées dans son down(). Leurs fonctions fixent leur
 * chemin de recherche (voir DeclencheursDeProtection::CHEMIN_DE_RECHERCHE)
 * et ne verrouillent aucune ligne d'une table en ajout seul.
 */
final class GardesDuStock
{
    /**
     * Le message du refus d'un stock ou d'un dernier mouvement compté
     * écrit autrement que par l'insertion d'un mouvement.
     */
    public const REFUS_STOCK_SANS_MOUVEMENT = 'produits.stock_quantite : le stock ne change que par un mouvement du grand livre, modification refusée';

    /**
     * Le message du refus d'un achat validé dont les lignes ne sont plus
     * ce que ses mouvements ont fait entrer en stock.
     */
    public const REFUS_ACHAT_SANS_SON_STOCK = 'lignes_achat : les lignes de cet achat validé et ses mouvements du grand livre ne concordent plus, refusé';

    /**
     * Les déclencheurs différés de la cohérence des achats, que SET
     * CONSTRAINTS désigne.
     */
    public const COHERENCE_DES_LIGNES = 'lignes_achat_stock_coherent';

    public const COHERENCE_DES_MOUVEMENTS = 'mouvements_stock_achat_coherent';

    /**
     * Le message du refus d'un produit inséré avec du stock ou un dernier
     * mouvement compté.
     */
    public const REFUS_PRODUIT_NE_AVEC_DU_STOCK = 'produits : un produit naît sans stock, qui n\'y entre que par un mouvement du grand livre, refusé';

    /**
     * Le message du refus d'un mouvement que ne justifie aucun document de
     * son magasin dans l'état voulu, ou dont le document ne porte pas son
     * produit.
     */
    public const REFUS_SANS_JUSTIFICATIF = 'mouvements_stock : mouvement sans le document de son magasin qui le justifie, refusé';

    /**
     * Le message du refus d'un mouvement au-delà de ce que porte son
     * document : ses lignes, ce que la vente a fait sortir et pas encore
     * repris, le stock et le coût moyen d'une cession, la sortie de cession
     * que rentre son annulation.
     */
    public const REFUS_AU_DELA_DU_DOCUMENT = 'mouvements_stock : mouvement au-delà de ce que porte son document, refusé';

    /**
     * Le message du refus d'un mouvement daté avant son document, ou à un
     * autre instant que son inscription.
     */
    public const REFUS_INSTANT = 'mouvements_stock : mouvement daté avant son document ou à un autre instant que son inscription, refusé';

    /**
     * Le message du refus d'un mouvement au nom d'un autre que l'auteur de
     * son document.
     */
    public const REFUS_AUTEUR = 'mouvements_stock : mouvement au nom d\'un autre que l\'auteur de son document, refusé';

    /**
     * Le déclencheur différé de l'auteur des mouvements, que SET
     * CONSTRAINTS désigne.
     */
    public const AUTEUR_DES_MOUVEMENTS = 'mouvements_stock_auteur_du_document';

    public static function poser(): void
    {
        $connexion = DB::connection();
        $variation = self::variation('NEW');

        DeclencheursDeProtection::ajoutSeul('mouvements_stock');

        foreach ([
            'mouvements_stock_quantite_positive' => 'quantite > 0',
            'mouvements_stock_cout_positif' => 'cout_unitaire_ht IS NULL OR cout_unitaire_ht >= 0',
            'mouvements_stock_type_connu' => 'type IN ('.self::liste(TypeMouvementStock::cases()).')',
            'mouvements_stock_cout_selon_le_type' => '(type IN ('.self::liste(array_values(array_filter(TypeMouvementStock::cases(), static fn (TypeMouvementStock $type): bool => $type->porteSonCout()))).')) = (cout_unitaire_ht IS NOT NULL)',
        ] as $nom => $condition) {
            $connexion->unprepared("ALTER TABLE mouvements_stock ADD CONSTRAINT {$nom} CHECK ({$condition})");
        }

        $connexion->unprepared('CREATE OR REPLACE FUNCTION compter_le_mouvement_de_stock() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '
            ."UPDATE produits SET stock_quantite = stock_quantite + {$variation}, dernier_mouvement_stock_id = NEW.id WHERE id = NEW.produit_id AND magasin_id = NEW.magasin_id; "
            .'RETURN NULL; END $$');
        $connexion->unprepared('CREATE TRIGGER mouvements_stock_compte AFTER INSERT ON mouvements_stock FOR EACH ROW EXECUTE FUNCTION compter_le_mouvement_de_stock()');

        $connexion->unprepared('CREATE OR REPLACE FUNCTION refus_stock_sans_mouvement() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ DECLARE compte mouvements_stock%ROWTYPE; BEGIN '
            .'IF NEW.stock_quantite IS NOT DISTINCT FROM OLD.stock_quantite AND NEW.dernier_mouvement_stock_id IS NOT DISTINCT FROM OLD.dernier_mouvement_stock_id THEN RETURN NEW; END IF; '
            .'SELECT * INTO compte FROM mouvements_stock m WHERE m.id = NEW.dernier_mouvement_stock_id AND m.produit_id = NEW.id AND m.magasin_id = NEW.magasin_id; '
            .'IF pg_trigger_depth() < 2 OR compte.id IS NULL OR NEW.dernier_mouvement_stock_id IS NOT DISTINCT FROM OLD.dernier_mouvement_stock_id '
            .'OR NEW.stock_quantite IS DISTINCT FROM OLD.stock_quantite + '.self::variation('compte').' '
            ."THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; RETURN NEW; END \$\$");
        $connexion->unprepared("CREATE TRIGGER produits_stock_par_le_grand_livre BEFORE UPDATE OF stock_quantite, dernier_mouvement_stock_id ON produits FOR EACH ROW EXECUTE FUNCTION refus_stock_sans_mouvement('".self::REFUS_STOCK_SANS_MOUVEMENT."')");
    }

    /**
     * La cohérence d'un achat validé et de son stock, contrôlée à la
     * validation de chaque transaction qui écrit une ligne d'achat ou un
     * mouvement dont la source est un achat (déclencheurs de contrainte
     * différés) : pour chaque produit qu'un achat a fait entrer en stock,
     * le net de ses mouvements venus de cet achat (réception et
     * ajustements de correction) égale la somme de ses lignes de l'achat
     * validé (zéro s'il n'y figure plus). La validation et la correction
     * d'un achat écrivent lignes et mouvements dans la même transaction ;
     * une ligne réécrite seule est refusée. Un produit que l'achat n'a
     * jamais fait entrer en stock (sans suivi à sa réception) n'est pas
     * contrôlé. Posée dans la migration de création de lignes_achat,
     * dernière table qu'elle lit, et retirée dans son down().
     */
    public static function poserLaCoherenceDesAchats(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('CREATE OR REPLACE FUNCTION refus_achat_sans_son_stock() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ DECLARE achat bigint; magasin bigint; BEGIN '
            ."IF TG_TABLE_NAME = 'lignes_achat' THEN "
            ."IF TG_OP = 'DELETE' THEN achat := OLD.facture_achat_id; magasin := OLD.magasin_id; ELSE achat := NEW.facture_achat_id; magasin := NEW.magasin_id; END IF; "
            ."ELSIF NEW.source_type IS DISTINCT FROM 'facture_achat' THEN RETURN NULL; "
            .'ELSE achat := NEW.source_id; magasin := NEW.magasin_id; END IF; '
            .'IF EXISTS (SELECT 1 FROM (SELECT m.produit_id, sum('.self::variation('m').") AS net FROM mouvements_stock m WHERE m.magasin_id = magasin AND m.source_type = 'facture_achat' AND m.source_id = achat GROUP BY m.produit_id) mouvements "
            ."WHERE mouvements.net <> coalesce((SELECT sum(l.quantite) FROM lignes_achat l JOIN factures_achat f ON f.magasin_id = l.magasin_id AND f.id = l.facture_achat_id WHERE l.magasin_id = magasin AND l.facture_achat_id = achat AND l.produit_id = mouvements.produit_id AND f.statut = 'validee'), 0)) "
            ."THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; RETURN NULL; END \$\$");

        foreach ([self::COHERENCE_DES_LIGNES => 'INSERT OR UPDATE OR DELETE ON lignes_achat', self::COHERENCE_DES_MOUVEMENTS => 'INSERT ON mouvements_stock'] as $nom => $evenements) {
            $connexion->unprepared("CREATE CONSTRAINT TRIGGER {$nom} AFTER {$evenements} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION refus_achat_sans_son_stock('".self::REFUS_ACHAT_SANS_SON_STOCK."')");
        }
    }

    /**
     * Un produit naît sans stock ni dernier mouvement compté : son stock
     * n'y entre que par un mouvement du grand livre, justifié par la
     * facture d'achat ou l'inventaire d'ouverture validé qui le fait naître
     * (voir poserLaProvenance()). Posée dans la migration de création des
     * produits, retirée dans son down().
     */
    public static function poserLaNaissanceSansStock(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('CREATE OR REPLACE FUNCTION refus_produit_ne_avec_du_stock() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '
            ."IF NEW.stock_quantite <> 0 OR NEW.dernier_mouvement_stock_id IS NOT NULL THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; RETURN NEW; END \$\$");
        $connexion->unprepared('CREATE TRIGGER produits_nes_sans_stock BEFORE INSERT ON produits FOR EACH ROW EXECUTE FUNCTION refus_produit_ne_avec_du_stock('.self::litteral(self::REFUS_PRODUIT_NE_AVEC_DU_STOCK).')');
    }

    public static function retirerLaNaissanceSansStock(): void
    {
        DB::connection()->unprepared('DROP FUNCTION IF EXISTS refus_produit_ne_avec_du_stock()');
    }

    /**
     * La provenance de chaque mouvement, contrôlée à son insertion, sous le
     * verrou de la ligne de son produit (deux mouvements simultanés d'un
     * même produit passent l'un après l'autre, le second voyant le
     * premier) :
     *
     * - sa source est le document de son magasin que son type désigne
     *   (TypeMouvementStock::justificatif()), jamais aucune : une facture
     *   numérotée qui n'est pas une rectificative pour une vente ; la ligne
     *   d'un avoir numéroté, hors régularisation, pour un retour client ;
     *   une facture d'achat validée pour une réception, et pour
     *   l'ajustement qui la corrige, avec son motif ; un inventaire
     *   d'ouverture validé ; un avoir fournisseur émis pour un retour au
     *   fournisseur ; le magasin lui-même, cédé, pour la sortie de cession
     *   et sa rentrée ;
     * - ce document porte son produit, et le net signé de tous les
     *   mouvements de ce document pour ce produit, celui-ci compris, tous
     *   types confondus, reste entre zéro et ses lignes ; après un
     *   ajustement, il égale ses lignes (zéro si le produit n'y figure
     *   plus) ; un retour client ne rend en plus jamais que ce que la vente
     *   (la facture, celle qu'elle rectifie et sa rectificative) a fait
     *   sortir et pas encore repris ;
     * - une sortie de cession sort tout le stock, positif, au coût moyen du
     *   produit ; une rentrée d'annulation reprend la dernière sortie de
     *   cession du produit, même quantité et même coût, une seule fois ;
     * - il survient à l'instant de son inscription (survenu_le égal à
     *   created_at), jamais avant son document (émission, validation).
     *
     * Posée à la fin de la migration de création de
     * lignes_inventaire_ouverture, dernière table qu'elle lit, et retirée
     * dans son down().
     */
    public static function poserLaProvenance(): void
    {
        $connexion = DB::connection();
        $variation = self::variation('m');
        $vente = self::alias(TypeMouvementStock::Vente);
        $retourClient = self::alias(TypeMouvementStock::RetourClient);

        $connexion->unprepared('CREATE OR REPLACE FUNCTION refus_mouvement_sans_justificatif() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ '
            .'DECLARE sens integer := 1; instant timestamp; plafond bigint; net bigint; sortie_restante bigint; vendue bigint; stock integer; cmup numeric; sortie mouvements_stock%ROWTYPE; BEGIN '
            .'SELECT p.stock_quantite, p.cmup_ht INTO stock, cmup FROM produits p WHERE p.id = NEW.produit_id AND p.magasin_id = NEW.magasin_id FOR UPDATE; '
            .'IF NEW.source_id IS NULL OR NEW.source_type IS DISTINCT FROM (CASE NEW.type '.implode(' ', array_map(static fn (TypeMouvementStock $type): string => "WHEN '{$type->value}' THEN '".self::alias($type)."'", TypeMouvementStock::cases())).' END) '
            ."THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
            ."IF NEW.survenu_le IS DISTINCT FROM NEW.created_at THEN RAISE EXCEPTION '%', TG_ARGV[2]; END IF; "
            ."IF NEW.type = '".TypeMouvementStock::Vente->value."' THEN sens := -1; "
            .'SELECT f.date_emission INTO instant FROM factures f WHERE f.magasin_id = NEW.magasin_id AND f.id = NEW.source_id AND f.numero IS NOT NULL AND f.facture_rectifiee_id IS NULL; '
            ."IF NOT FOUND THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
            .'SELECT sum(l.quantite) INTO plafond FROM lignes_facture l WHERE l.magasin_id = NEW.magasin_id AND l.facture_id = NEW.source_id AND l.produit_id = NEW.produit_id; '
            ."ELSIF NEW.type = '".TypeMouvementStock::RetourClient->value."' THEN "
            .'SELECT a.date_emission, la.quantite, lf.facture_id INTO instant, plafond, vendue FROM lignes_avoir la '
            .'JOIN avoirs a ON a.magasin_id = la.magasin_id AND a.id = la.avoir_id JOIN lignes_facture lf ON lf.magasin_id = la.magasin_id AND lf.id = la.ligne_facture_id '
            .'WHERE la.magasin_id = NEW.magasin_id AND la.id = NEW.source_id AND la.produit_id = NEW.produit_id AND a.numero IS NOT NULL AND NOT a.regularisation; '
            ."IF NOT FOUND THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
            .'WITH RECURSIVE avant(id, rectifiee) AS (SELECT f.id, f.facture_rectifiee_id FROM factures f WHERE f.magasin_id = NEW.magasin_id AND f.id = vendue '
            .'UNION SELECT f.id, f.facture_rectifiee_id FROM factures f JOIN avant ON f.id = avant.rectifiee WHERE f.magasin_id = NEW.magasin_id), '
            .'apres(id) AS (SELECT vendue UNION SELECT f.id FROM factures f JOIN apres ON f.facture_rectifiee_id = apres.id WHERE f.magasin_id = NEW.magasin_id), '
            .'factures_de_la_vente(id) AS (SELECT avant.id FROM avant UNION SELECT apres.id FROM apres) '
            ."SELECT -coalesce(sum({$variation}), 0) INTO sortie_restante FROM mouvements_stock m WHERE m.magasin_id = NEW.magasin_id AND m.produit_id = NEW.produit_id "
            ."AND ((m.source_type = '{$vente}' AND m.source_id IN (SELECT id FROM factures_de_la_vente)) OR (m.source_type = '{$retourClient}' AND m.source_id IN "
            .'(SELECT la.id FROM lignes_avoir la JOIN lignes_facture lf ON lf.magasin_id = la.magasin_id AND lf.id = la.ligne_facture_id WHERE la.magasin_id = NEW.magasin_id AND lf.facture_id IN (SELECT id FROM factures_de_la_vente)))); '
            ."IF NEW.quantite > sortie_restante THEN RAISE EXCEPTION '%', TG_ARGV[1]; END IF; "
            ."ELSIF NEW.type IN ('".TypeMouvementStock::ReceptionAchat->value."', '".TypeMouvementStock::AjustementEntree->value."', '".TypeMouvementStock::AjustementSortie->value."') THEN "
            ."SELECT f.date_validation INTO instant FROM factures_achat f WHERE f.magasin_id = NEW.magasin_id AND f.id = NEW.source_id AND f.statut = 'validee'; "
            ."IF NOT FOUND THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
            .'SELECT sum(l.quantite) INTO plafond FROM lignes_achat l WHERE l.magasin_id = NEW.magasin_id AND l.facture_achat_id = NEW.source_id AND l.produit_id = NEW.produit_id; '
            ."IF NEW.type <> '".TypeMouvementStock::ReceptionAchat->value."' THEN "
            ."IF NEW.note IS NULL OR btrim(NEW.note) = '' THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; plafond := coalesce(plafond, 0); END IF; "
            ."ELSIF NEW.type = '".TypeMouvementStock::InventaireOuverture->value."' THEN "
            ."SELECT i.date_validation INTO instant FROM inventaires_ouverture i WHERE i.magasin_id = NEW.magasin_id AND i.id = NEW.source_id AND i.statut = 'valide'; "
            ."IF NOT FOUND THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
            .'SELECT sum(l.quantite) INTO plafond FROM lignes_inventaire_ouverture l WHERE l.magasin_id = NEW.magasin_id AND l.inventaire_ouverture_id = NEW.source_id AND l.produit_id = NEW.produit_id; '
            ."ELSIF NEW.type = '".TypeMouvementStock::RetourFournisseur->value."' THEN sens := -1; "
            ."SELECT a.date_emission INTO instant FROM avoirs_fournisseur a WHERE a.magasin_id = NEW.magasin_id AND a.id = NEW.source_id AND a.statut = 'emise'; "
            ."IF NOT FOUND THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
            .'SELECT sum(l.quantite) INTO plafond FROM lignes_avoir_fournisseur l WHERE l.magasin_id = NEW.magasin_id AND l.avoir_fournisseur_id = NEW.source_id AND l.produit_id = NEW.produit_id; '
            .'ELSE '
            ."PERFORM 1 FROM magasins mg WHERE mg.id = NEW.magasin_id AND mg.id = NEW.source_id AND mg.motif_fermeture = 'cession'; "
            ."IF NOT FOUND THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
            ."IF NEW.type = '".TypeMouvementStock::SortieCession->value."' THEN "
            ."IF NEW.quantite IS DISTINCT FROM stock OR NEW.cout_unitaire_ht IS DISTINCT FROM cmup THEN RAISE EXCEPTION '%', TG_ARGV[1]; END IF; "
            .'ELSE '
            ."SELECT * INTO sortie FROM mouvements_stock m WHERE m.magasin_id = NEW.magasin_id AND m.produit_id = NEW.produit_id AND m.type = '".TypeMouvementStock::SortieCession->value."' ORDER BY m.id DESC LIMIT 1; "
            .'IF sortie.id IS NULL OR sortie.quantite <> NEW.quantite OR sortie.cout_unitaire_ht IS DISTINCT FROM NEW.cout_unitaire_ht '
            ."OR EXISTS (SELECT 1 FROM mouvements_stock m WHERE m.magasin_id = NEW.magasin_id AND m.produit_id = NEW.produit_id AND m.type = '".TypeMouvementStock::EntreeAnnulationCession->value."' AND m.id > sortie.id) "
            ."THEN RAISE EXCEPTION '%', TG_ARGV[1]; END IF; END IF; "
            .'RETURN NEW; END IF; '
            ."IF instant IS NULL OR NEW.survenu_le < instant THEN RAISE EXCEPTION '%', TG_ARGV[2]; END IF; "
            ."IF plafond IS NULL THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
            ."SELECT sens * (coalesce(sum({$variation}), 0) + ".self::variation('NEW').') INTO net FROM mouvements_stock m '
            .'WHERE m.magasin_id = NEW.magasin_id AND m.produit_id = NEW.produit_id AND m.source_type = NEW.source_type AND m.source_id = NEW.source_id; '
            ."IF net < 0 OR net > plafond OR (NEW.type IN ('".TypeMouvementStock::AjustementEntree->value."', '".TypeMouvementStock::AjustementSortie->value."') AND net <> plafond) "
            ."THEN RAISE EXCEPTION '%', TG_ARGV[1]; END IF; "
            .'RETURN NEW; END $$');
        $connexion->unprepared('CREATE TRIGGER mouvements_stock_justifies BEFORE INSERT ON mouvements_stock FOR EACH ROW EXECUTE FUNCTION refus_mouvement_sans_justificatif('
            .self::litteral(self::REFUS_SANS_JUSTIFICATIF).', '.self::litteral(self::REFUS_AU_DELA_DU_DOCUMENT).', '.self::litteral(self::REFUS_INSTANT).')');
    }

    public static function retirerLaProvenance(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('DROP TRIGGER IF EXISTS mouvements_stock_justifies ON mouvements_stock');
        $connexion->unprepared('DROP FUNCTION IF EXISTS refus_mouvement_sans_justificatif()');
    }

    /**
     * L'auteur de chaque mouvement, contrôlé à la validation de la
     * transaction qui l'inscrit (déclencheur de contrainte différé), quand
     * l'acte qui le justifie est enregistré : celui que le document
     * enregistre pour cet acte. L'opérateur que le registre fiscal inscrit
     * pour l'émission de la facture (vente) ou de l'avoir (retour client) ;
     * l'auteur que le journal d'audit inscrit pour la validation de la
     * facture d'achat (réception) ou l'émission de l'avoir fournisseur
     * (retour au fournisseur), et pour une correction de la facture
     * d'achat (ajustement), qu'il a faite ; celui qui a validé l'inventaire
     * d'ouverture ; le propriétaire de la société pour une sortie de
     * cession ; personne pour sa rentrée, que fait la procédure de
     * l'éditeur. Posée à la fin de la migration de création de
     * enregistrements_fiscaux, dernière table qu'elle lit, et retirée dans
     * son down().
     */
    public static function poserLAuteurDesMouvements(): void
    {
        $connexion = DB::connection();
        $achat = self::alias(TypeMouvementStock::ReceptionAchat);

        $connexion->unprepared('CREATE OR REPLACE FUNCTION refus_mouvement_d_un_autre_auteur() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ '
            .'DECLARE attendu bigint; trouve boolean := false; BEGIN '
            ."IF NEW.type = '".TypeMouvementStock::Vente->value."' THEN "
            ."SELECT e.operateur_id INTO attendu FROM enregistrements_fiscaux e WHERE e.magasin_id = NEW.magasin_id AND e.chaine = 'vente' AND e.document_type = '".(new Facture)->getMorphClass()."' AND e.document_id = NEW.source_id ORDER BY e.id LIMIT 1; "
            .'trouve := FOUND; '
            ."ELSIF NEW.type = '".TypeMouvementStock::RetourClient->value."' THEN "
            ."SELECT e.operateur_id INTO attendu FROM enregistrements_fiscaux e JOIN lignes_avoir la ON la.magasin_id = e.magasin_id AND la.avoir_id = e.document_id WHERE e.magasin_id = NEW.magasin_id AND e.chaine = 'vente' AND e.document_type = '".(new Avoir)->getMorphClass()."' AND la.id = NEW.source_id ORDER BY e.id LIMIT 1; "
            .'trouve := FOUND; '
            ."ELSIF NEW.type IN ('".TypeMouvementStock::ReceptionAchat->value."', '".TypeMouvementStock::RetourFournisseur->value."') THEN "
            ."SELECT j.auteur_id INTO attendu FROM journal_audit j WHERE j.magasin_id = NEW.magasin_id AND j.cible_type = NEW.source_type AND j.cible_id = NEW.source_id AND j.action = (CASE WHEN NEW.source_type = '{$achat}' THEN '".ActionAuditee::FactureAchatValidee->value."' ELSE '".ActionAuditee::AvoirFournisseurEmis->value."' END) ORDER BY j.id LIMIT 1; "
            .'trouve := FOUND; '
            ."ELSIF NEW.type IN ('".TypeMouvementStock::AjustementEntree->value."', '".TypeMouvementStock::AjustementSortie->value."') THEN "
            ."attendu := NEW.cree_par; trouve := EXISTS (SELECT 1 FROM journal_audit j WHERE j.magasin_id = NEW.magasin_id AND j.cible_type = NEW.source_type AND j.cible_id = NEW.source_id AND j.action = '".ActionAuditee::FactureAchatModifiee->value."' AND j.auteur_id = NEW.cree_par); "
            ."ELSIF NEW.type = '".TypeMouvementStock::InventaireOuverture->value."' THEN "
            .'SELECT i.valide_par INTO attendu FROM inventaires_ouverture i WHERE i.magasin_id = NEW.magasin_id AND i.id = NEW.source_id; trouve := FOUND; '
            ."ELSIF NEW.type = '".TypeMouvementStock::SortieCession->value."' THEN "
            ."attendu := NEW.cree_par; trouve := EXISTS (SELECT 1 FROM users u JOIN magasins mg ON mg.societe_id = u.societe_id WHERE mg.id = NEW.magasin_id AND u.id = NEW.cree_par AND u.role_societe = '".RoleSociete::Proprietaire->value."'); "
            .'ELSE '
            ."IF NEW.cree_par IS NOT NULL THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; RETURN NULL; "
            .'END IF; '
            ."IF NOT trouve OR attendu IS NULL OR attendu IS DISTINCT FROM NEW.cree_par THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
            .'RETURN NULL; END $$');
        $connexion->unprepared('CREATE CONSTRAINT TRIGGER '.self::AUTEUR_DES_MOUVEMENTS.' AFTER INSERT ON mouvements_stock DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION refus_mouvement_d_un_autre_auteur('.self::litteral(self::REFUS_AUTEUR).')');
    }

    public static function retirerLAuteurDesMouvements(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('DROP TRIGGER IF EXISTS '.self::AUTEUR_DES_MOUVEMENTS.' ON mouvements_stock');
        $connexion->unprepared('DROP FUNCTION IF EXISTS refus_mouvement_d_un_autre_auteur()');
    }

    public static function retirerLaCoherenceDesAchats(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('DROP TRIGGER IF EXISTS '.self::COHERENCE_DES_MOUVEMENTS.' ON mouvements_stock');
        $connexion->unprepared('DROP FUNCTION IF EXISTS refus_achat_sans_son_stock()');
    }

    public static function retirer(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('DROP TRIGGER IF EXISTS produits_stock_par_le_grand_livre ON produits');
        $connexion->unprepared('DROP FUNCTION IF EXISTS refus_stock_sans_mouvement()');
        $connexion->unprepared('DROP FUNCTION IF EXISTS compter_le_mouvement_de_stock()');
        DeclencheursDeProtection::retirer('mouvements_stock');
    }

    /**
     * La variation qu'apporte au stock le mouvement $ligne (NEW, ou une
     * variable de ligne) : sa quantité pour une entrée, son opposé pour une
     * sortie ; jamais un type inconnu, que la contrainte du type refuse.
     */
    private static function variation(string $ligne): string
    {
        $entrees = array_values(array_filter(TypeMouvementStock::cases(), static fn (TypeMouvementStock $type): bool => $type->estEntree()));

        return "(CASE WHEN {$ligne}.type IN (".self::liste($entrees).") THEN {$ligne}.quantite ELSE -{$ligne}.quantite END)";
    }

    /**
     * Le nom stable du document qui justifie un mouvement de ce type (voir
     * TypeMouvementStock::aliasDuJustificatif()).
     */
    private static function alias(TypeMouvementStock $type): string
    {
        return $type->aliasDuJustificatif();
    }

    /**
     * Un texte écrit en littéral SQL, ses apostrophes doublées.
     */
    private static function litteral(string $texte): string
    {
        return "'".str_replace("'", "''", $texte)."'";
    }

    /**
     * Les valeurs de ces types, écrites en SQL.
     *
     * @param  list<TypeMouvementStock>  $types
     */
    private static function liste(array $types): string
    {
        return implode(', ', array_map(static fn (TypeMouvementStock $type): string => "'{$type->value}'", $types));
    }
}

<?php

namespace App\Domain\Facturation\Support;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Support\Facades\DB;

/**
 * Les gardes par lesquelles la base elle-même tient chaque ticket Z à la
 * clôture qui l'a produit (voir CloturerCaisseAction), même face à une
 * requête directe :
 *
 * - un ticket Z ne s'enregistre qu'avec l'événement 50 (clôture de
 *   période) qui le signe au journal des événements de son magasin : un
 *   déclencheur de contrainte différé le contrôle à la validation de la
 *   transaction, où la clôture a inscrit l'un et l'autre (voir poser()) ;
 *   un ticket Z inséré seul est refusé, la transaction entière annulée ;
 * - le lien d'un ticket ou d'un avoir à son ticket Z ne se pose que vers
 *   le dernier ticket Z de son magasin, celui que la clôture vient
 *   d'écrire, puis ne change plus (voir POSE_VERS_LE_DERNIER_TICKET_Z,
 *   que les migrations des factures et des avoirs donnent à
 *   DeclencheursDeProtection::colonneFigee()).
 *
 * Le déclencheur différé lit la table du registre : il se pose à la fin
 * de la migration de création de enregistrements_fiscaux, dernière table
 * qu'il lit, et se retire dans son down(). Sa fonction ne verrouille
 * aucune ligne et fixe son chemin de recherche (voir
 * DeclencheursDeProtection::CHEMIN_DE_RECHERCHE).
 */
final class GardesDesClotures
{
    /**
     * Le message du refus d'un ticket Z qu'aucune clôture n'a signé.
     */
    public const REFUS_SANS_CLOTURE_SIGNEE = 'clotures_caisse : ticket Z sans sa clôture signée au journal des événements, refusé';

    /**
     * La condition à laquelle un ticket ou un avoir reçoit son ticket Z :
     * le dernier de son magasin.
     */
    public const POSE_VERS_LE_DERNIER_TICKET_Z = 'NEW.cloture_caisse_id = (SELECT max(z.id) FROM clotures_caisse z WHERE z.magasin_id = NEW.magasin_id)';

    /**
     * Le nom du déclencheur différé, que SET CONSTRAINTS désigne.
     */
    public const DECLENCHEUR = 'clotures_caisse_signee';

    public static function poser(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('CREATE OR REPLACE FUNCTION refus_ticket_z_non_signe() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '
            ."IF NOT EXISTS (SELECT 1 FROM enregistrements_fiscaux e WHERE e.magasin_id = NEW.magasin_id AND e.chaine = 'evenement' AND e.type = '50' AND e.document_type = 'cloture_caisse' AND e.document_id = NEW.id) "
            ."THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; RETURN NULL; END \$\$");
        $connexion->unprepared('CREATE CONSTRAINT TRIGGER '.self::DECLENCHEUR." AFTER INSERT ON clotures_caisse DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION refus_ticket_z_non_signe('".self::REFUS_SANS_CLOTURE_SIGNEE."')");
    }

    public static function retirer(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('DROP TRIGGER IF EXISTS '.self::DECLENCHEUR.' ON clotures_caisse');
        $connexion->unprepared('DROP FUNCTION IF EXISTS refus_ticket_z_non_signe()');
    }
}

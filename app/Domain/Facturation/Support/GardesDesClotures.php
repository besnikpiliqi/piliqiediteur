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
 *   transaction, où la clôture a inscrit l'un et l'autre ; un ticket Z
 *   inséré seul, encore là à la validation, est refusé, la transaction
 *   entière annulée ;
 * - la table des tickets Z est en ajout seul : un ticket Z ne se modifie
 *   ni ne se vide, et seul se supprime un ticket Z que rien ne signe,
 *   écrit en contournant la garde précédente, que retire la procédure de
 *   l'éditeur (voir RetirerUnTicketZNonSigneAction) ;
 * - le lien d'un ticket ou d'un avoir à son ticket Z ne se pose que vers
 *   le dernier ticket Z de son magasin, celui que la clôture vient
 *   d'écrire, puis ne change plus (voir POSE_VERS_LE_DERNIER_TICKET_Z),
 *   sauf pour se défaire d'un ticket Z que rien ne signe, avant son
 *   retrait.
 *
 * Ces gardes lisent la table du registre : elles se posent à la fin de la
 * migration de création de enregistrements_fiscaux, dernière table
 * qu'elles lisent, et se retirent dans son down(). Leurs fonctions ne
 * verrouillent aucune ligne et fixent leur chemin de recherche (voir
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

    /**
     * Les documents rattachés à leur ticket Z.
     */
    private const DOCUMENTS = ['factures', 'avoirs'];

    public static function poser(): void
    {
        $connexion = DB::connection();

        $connexion->unprepared('CREATE OR REPLACE FUNCTION refus_ticket_z_non_signe() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '
            .'IF EXISTS (SELECT 1 FROM clotures_caisse z WHERE z.id = NEW.id) AND '.self::nonSigne('NEW', 'id').' '
            ."THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; RETURN NULL; END \$\$");
        $connexion->unprepared('CREATE CONSTRAINT TRIGGER '.self::DECLENCHEUR." AFTER INSERT ON clotures_caisse DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION refus_ticket_z_non_signe('".self::REFUS_SANS_CLOTURE_SIGNEE."')");

        DeclencheursDeProtection::ajoutSeul('clotures_caisse', suppressionPermiseSi: self::nonSigne('OLD', 'id'));

        foreach (self::DOCUMENTS as $documents) {
            DeclencheursDeProtection::colonneFigee($documents, 'cloture_caisse_id', poseeSeulementSi: self::POSE_VERS_LE_DERNIER_TICKET_Z, effacableSi: self::nonSigne('OLD', 'cloture_caisse_id'));
        }
    }

    public static function retirer(): void
    {
        $connexion = DB::connection();

        foreach (self::DOCUMENTS as $documents) {
            $connexion->unprepared("DROP TRIGGER IF EXISTS {$documents}_cloture_caisse_id_figee ON {$documents}");
            DeclencheursDeProtection::retirerLaColonneFigee($documents, 'cloture_caisse_id');
        }

        foreach (['update', 'delete', 'truncate'] as $operation) {
            $connexion->unprepared("DROP TRIGGER IF EXISTS clotures_caisse_ajout_seul_{$operation} ON clotures_caisse");
        }

        DeclencheursDeProtection::retirer('clotures_caisse');
        $connexion->unprepared('DROP TRIGGER IF EXISTS '.self::DECLENCHEUR.' ON clotures_caisse');
        $connexion->unprepared('DROP FUNCTION IF EXISTS refus_ticket_z_non_signe()');
    }

    /**
     * La condition SQL d'un ticket Z que rien ne signe : aucun événement 50
     * du journal des événements de son magasin ne le désigne.
     *
     * @param  literal-string  $ligne  la ligne lue (NEW, OLD)
     * @param  literal-string  $colonne  sa colonne qui désigne le ticket Z
     * @return literal-string
     */
    private static function nonSigne(string $ligne, string $colonne): string
    {
        return "NOT EXISTS (SELECT 1 FROM enregistrements_fiscaux e WHERE e.magasin_id = {$ligne}.magasin_id AND e.chaine = 'evenement' AND e.type = '50' AND e.document_type = 'cloture_caisse' AND e.document_id = {$ligne}.{$colonne})";
    }
}

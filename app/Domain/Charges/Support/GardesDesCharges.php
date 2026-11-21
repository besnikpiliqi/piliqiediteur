<?php

namespace App\Domain\Charges\Support;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Support\LitteralSql;
use Illuminate\Support\Facades\DB;

/**
 * Les gardes par lesquelles la base elle-même tient les charges de la
 * société (décision de l'éditeur du 14/11/2026, lot CHARGES ; voir
 * Charge), même écrites par DB::table(), une requête brute ou une action
 * défectueuse :
 * - une charge n'existe que dans une société qui a des magasins, jamais
 *   un cabinet d'expertise comptable ; son magasin, quand elle en a un,
 *   est un magasin de sa société : la clé étrangère composite
 *   (societe_id, magasin_id) refuse un magasin qui n'existe pas ou d'une
 *   autre société ;
 * - une charge validée ne s'efface jamais, et ne s'écrit plus que par le
 *   geste « Corriger », qui compte chaque correction : son état reste
 *   « validée », sa validation et son dépôt ne changent plus, et le
 *   compteur de ses corrections avance d'une unité à chaque écriture ;
 *   avant sa validation, ce compteur reste à zéro ;
 * - à la validation de la transaction, une charge à valider ou validée a
 *   au moins une ligne, une pièce sans chiffres n'en a aucune et a sa
 *   pièce, et une charge validée a sa pièce : une TVA ne se déduit pas
 *   sans pièce ;
 * - les lignes d'une charge validée ne changent que par une correction,
 *   dans la transaction qui l'a écrite : les anciennes s'effacent et les
 *   nouvelles, de la version de la correction, s'ajoutent ; aucune ne se
 *   modifie, et aucune ne s'ajoute ni ne s'efface hors d'une correction ;
 *   les actions écrivent donc les lignes et la pièce d'une charge avant de
 *   la valider ;
 * - sa pièce ne se modifie jamais : seule avance sa dernière analyse par
 *   l'antivirus, et son blocage se pose une fois, avec sa menace ; elle ne
 *   s'efface que d'une charge qui n'est pas validée.
 * L'état et la validation d'une charge restent cohérents par les
 * contraintes CHECK de sa table (voir la migration create_charges_table).
 * Une fonction plpgsql par garde ; celles des lignes et des pièces
 * verrouillent d'abord, en partage, la ligne de leur charge, pour qu'une
 * validation ou une correction simultanée passe tout entière avant ou
 * après elles.
 */
final class GardesDesCharges
{
    private const SOCIETE = 'charges_d_une_societe_a_magasins';

    private const VALIDEE_FIGEE = 'charges_validee_figee';

    private const VALIDEE_JAMAIS_EFFACEE = 'charges_validee_jamais_effacee';

    public const COHERENTE = 'charges_coherente';

    private const LIGNES = 'lignes_de_charge_d_une_charge_validee';

    private const PIECES_JAMAIS_MODIFIEES = 'pieces_de_charge_jamais_modifiees';

    private const PIECES_EFFACEES = 'pieces_de_charge_effacees_avant_la_validation';

    /**
     * Le réglage de la transaction qui retient les corrections acceptées
     * d'une charge validée, « ,{id}:{corrections}, » chacune : les lignes
     * d'une charge validée ne s'écrivent que dans la transaction de sa
     * correction, et seulement celles de sa version.
     */
    private const CORRECTIONS_EN_COURS = 'piliqi.corrections_de_charge';

    private const REFUS_SOCIETE = 'une charge n\'existe que dans une société qui a des magasins, jamais dans un cabinet d\'expertise comptable';

    private const REFUS_FIGEE = 'une charge validée ne s\'écrit plus que par une correction, qui compte chacune ; avant sa validation, elle n\'a aucune correction';

    private const REFUS_EFFACEMENT = 'une charge validée ne s\'efface jamais';

    private const REFUS_INCOHERENTE = 'une charge à valider ou validée a ses chiffres, une pièce sans chiffres n\'en a aucun et a sa pièce, et une charge validée a sa pièce';

    private const REFUS_LIGNES = 'les lignes d\'une charge validée ne changent que par une correction';

    private const REFUS_PIECE_MODIFIEE = 'la pièce d\'une charge ne se modifie jamais : seule sa dernière analyse avance, et son blocage par l\'antivirus se pose une fois';

    private const REFUS_PIECE_EFFACEE = 'la pièce d\'une charge validée ne s\'efface jamais';

    /**
     * Les colonnes d'une charge validée qu'une correction ne change jamais :
     * son identité, son dépôt, son origine et sa validation.
     */
    private const FIGEES = ['id', 'uuid', 'societe_id', 'origine', 'deposee_par', 'deposee_par_nom', 'validee_par', 'validee_par_compte', 'validee_par_nom', 'validee_le', 'created_at'];

    public static function poserSurLesCharges(): void
    {
        $connexion = DB::connection();
        $cabinet = "'".TypeDActivite::Cabinet->value."'";
        $figees = implode(', ', array_map(static fn (string $colonne): string => "NEW.{$colonne}", self::FIGEES));
        $figeesAvant = implode(', ', array_map(static fn (string $colonne): string => "OLD.{$colonne}", self::FIGEES));

        self::fonction(self::SOCIETE, 'BEGIN '
            ."IF COALESCE((SELECT type_activite FROM societes WHERE id = NEW.societe_id), {$cabinet}) = {$cabinet} THEN RAISE EXCEPTION '%', ".self::message(self::SOCIETE, self::REFUS_SOCIETE).'; END IF; '
            .'RETURN NEW; END');
        $connexion->unprepared('CREATE TRIGGER '.self::SOCIETE.' BEFORE INSERT OR UPDATE OF societe_id ON charges FOR EACH ROW EXECUTE FUNCTION garde_'.self::SOCIETE.'()');

        // Une correction acceptée se retient jusqu'à la fin de la
        // transaction (voir CORRECTIONS_EN_COURS) : ses lignes peuvent alors
        // s'écrire (voir poserSurLesLignes()).
        self::fonction(self::VALIDEE_FIGEE, 'BEGIN '
            ."IF (OLD.etat = 'validee' AND (NEW.etat <> 'validee' OR NEW.corrections <> OLD.corrections + 1 OR ({$figees}) IS DISTINCT FROM ({$figeesAvant}))) "
            ."OR (OLD.etat <> 'validee' AND NEW.corrections <> OLD.corrections) "
            ."THEN RAISE EXCEPTION '%', ".self::message(self::VALIDEE_FIGEE, self::REFUS_FIGEE).'; END IF; '
            ."IF OLD.etat = 'validee' THEN PERFORM set_config('".self::CORRECTIONS_EN_COURS."', COALESCE(current_setting('".self::CORRECTIONS_EN_COURS."', TRUE), '') || ',' || NEW.id || ':' || NEW.corrections || ',', TRUE); END IF; "
            .'RETURN NEW; END');
        $connexion->unprepared('CREATE TRIGGER '.self::VALIDEE_FIGEE.' BEFORE UPDATE ON charges FOR EACH ROW EXECUTE FUNCTION garde_'.self::VALIDEE_FIGEE.'()');

        self::fonction(self::VALIDEE_JAMAIS_EFFACEE, "BEGIN IF OLD.etat = 'validee' THEN RAISE EXCEPTION '%', ".self::message(self::VALIDEE_JAMAIS_EFFACEE, self::REFUS_EFFACEMENT).'; END IF; RETURN OLD; END');
        $connexion->unprepared('CREATE TRIGGER '.self::VALIDEE_JAMAIS_EFFACEE.' BEFORE DELETE ON charges FOR EACH ROW EXECUTE FUNCTION garde_'.self::VALIDEE_JAMAIS_EFFACEE.'()');

        // À la validation de la transaction : la charge telle qu'elle est
        // alors, relue, car les lignes et la pièce s'écrivent après elle.
        self::fonction(self::COHERENTE, 'DECLARE charge RECORD; lignes BOOLEAN; piece BOOLEAN; BEGIN '
            .'SELECT id, etat INTO charge FROM charges WHERE id = NEW.id; '
            .'IF NOT FOUND THEN RETURN NULL; END IF; '
            .'lignes := EXISTS (SELECT 1 FROM lignes_de_charge WHERE charge_id = charge.id); '
            .'piece := EXISTS (SELECT 1 FROM pieces_de_charge WHERE charge_id = charge.id); '
            ."IF (charge.etat IN ('a_valider', 'validee') AND NOT lignes) OR (charge.etat = 'piece_sans_chiffres' AND (lignes OR NOT piece)) OR (charge.etat = 'validee' AND NOT piece) "
            ."THEN RAISE EXCEPTION '%', ".self::message(self::COHERENTE, self::REFUS_INCOHERENTE).'; END IF; RETURN NULL; END');
        $connexion->unprepared('CREATE CONSTRAINT TRIGGER '.self::COHERENTE.' AFTER INSERT OR UPDATE ON charges DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION garde_'.self::COHERENTE.'()');
    }

    public static function retirerDesCharges(): void
    {
        self::retirer('charges', [self::SOCIETE, self::VALIDEE_FIGEE, self::VALIDEE_JAMAIS_EFFACEE, self::COHERENTE]);
    }

    public static function poserSurLesLignes(): void
    {
        self::fonction(self::LIGNES, 'DECLARE la_charge RECORD; BEGIN '
            .'SELECT id, etat, corrections INTO la_charge FROM charges WHERE id = (CASE WHEN TG_OP = \'DELETE\' THEN OLD.charge_id ELSE NEW.charge_id END) FOR SHARE; '
            ."IF FOUND AND la_charge.etat = 'validee' AND ("
            ."TG_OP = 'UPDATE' "
            ."OR position(',' || la_charge.id || ':' || la_charge.corrections || ',' IN COALESCE(current_setting('".self::CORRECTIONS_EN_COURS."', TRUE), '')) = 0 "
            ."OR (TG_OP = 'INSERT' AND NEW.version <> la_charge.corrections) "
            ."OR (TG_OP = 'DELETE' AND OLD.version >= la_charge.corrections)) "
            ."THEN RAISE EXCEPTION '%', ".self::message(self::LIGNES, self::REFUS_LIGNES).'; END IF; '
            ."IF TG_OP = 'DELETE' THEN RETURN OLD; END IF; RETURN NEW; END");
        DB::connection()->unprepared('CREATE TRIGGER '.self::LIGNES.' BEFORE INSERT OR UPDATE OR DELETE ON lignes_de_charge FOR EACH ROW EXECUTE FUNCTION garde_'.self::LIGNES.'()');
        // Une ligne effacée ou ajoutée revérifie sa charge à la validation
        // de la transaction (voir poserSurLesCharges()) ; la pièce aussi
        // (voir poserSurLesPieces()).
        self::fonctionParLaCharge();
        DB::connection()->unprepared('CREATE CONSTRAINT TRIGGER '.self::COHERENTE.'_lignes AFTER INSERT OR DELETE ON lignes_de_charge DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION garde_'.self::COHERENTE.'_par_la_charge()');
    }

    public static function retirerDesLignes(): void
    {
        DB::connection()->unprepared('DROP TRIGGER IF EXISTS '.self::COHERENTE.'_lignes ON lignes_de_charge');
        DB::connection()->unprepared('DROP FUNCTION IF EXISTS garde_'.self::COHERENTE.'_par_la_charge()');
        self::retirer('lignes_de_charge', [self::LIGNES]);
    }

    public static function poserSurLesPieces(): void
    {
        $connexion = DB::connection();
        $modifiee = '(NEW.id, NEW.uuid, NEW.societe_id, NEW.charge_id, NEW.nom, NEW.type_mime, NEW.taille, NEW.empreinte, NEW.chemin, NEW.ajoutee_par, NEW.ajoutee_par_nom, NEW.created_at) '
            .'IS DISTINCT FROM (OLD.id, OLD.uuid, OLD.societe_id, OLD.charge_id, OLD.nom, OLD.type_mime, OLD.taille, OLD.empreinte, OLD.chemin, OLD.ajoutee_par, OLD.ajoutee_par_nom, OLD.created_at) '
            .'OR NEW.analysee_le < OLD.analysee_le '
            .'OR (OLD.bloquee_le IS NOT NULL AND (NEW.bloquee_le, NEW.menace) IS DISTINCT FROM (OLD.bloquee_le, OLD.menace)) '
            .'OR ((NEW.bloquee_le IS NULL) <> (NEW.menace IS NULL))';

        self::fonction(self::PIECES_JAMAIS_MODIFIEES, "BEGIN IF {$modifiee} THEN RAISE EXCEPTION '%', ".self::message(self::PIECES_JAMAIS_MODIFIEES, self::REFUS_PIECE_MODIFIEE).'; END IF; RETURN NEW; END');
        $connexion->unprepared('CREATE TRIGGER '.self::PIECES_JAMAIS_MODIFIEES.' BEFORE UPDATE ON pieces_de_charge FOR EACH ROW EXECUTE FUNCTION garde_'.self::PIECES_JAMAIS_MODIFIEES.'()');

        self::fonction(self::PIECES_EFFACEES, 'BEGIN '
            .'PERFORM 1 FROM charges WHERE id = OLD.charge_id FOR SHARE; '
            ."IF COALESCE((SELECT etat FROM charges WHERE id = OLD.charge_id), '') = 'validee' THEN RAISE EXCEPTION '%', ".self::message(self::PIECES_EFFACEES, self::REFUS_PIECE_EFFACEE).'; END IF; RETURN OLD; END');
        $connexion->unprepared('CREATE TRIGGER '.self::PIECES_EFFACEES.' BEFORE DELETE ON pieces_de_charge FOR EACH ROW EXECUTE FUNCTION garde_'.self::PIECES_EFFACEES.'()');
        $connexion->unprepared('CREATE CONSTRAINT TRIGGER '.self::COHERENTE.'_pieces AFTER INSERT OR DELETE ON pieces_de_charge DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION garde_'.self::COHERENTE.'_par_la_charge()');
    }

    public static function retirerDesPieces(): void
    {
        DB::connection()->unprepared('DROP TRIGGER IF EXISTS '.self::COHERENTE.'_pieces ON pieces_de_charge');
        self::retirer('pieces_de_charge', [self::PIECES_JAMAIS_MODIFIEES, self::PIECES_EFFACEES]);
    }

    /**
     * La cohérence d'une charge revérifiée depuis une ligne ou sa pièce :
     * la même que celle de la charge (voir poserSurLesCharges()), pour la
     * charge de la ligne ou de la pièce, si elle existe encore.
     */
    private static function fonctionParLaCharge(): void
    {
        self::fonction(self::COHERENTE.'_par_la_charge', 'DECLARE charge RECORD; lignes BOOLEAN; piece BOOLEAN; BEGIN '
            ."SELECT id, etat INTO charge FROM charges WHERE id = (CASE WHEN TG_OP = 'DELETE' THEN OLD.charge_id ELSE NEW.charge_id END); "
            .'IF NOT FOUND THEN RETURN NULL; END IF; '
            .'lignes := EXISTS (SELECT 1 FROM lignes_de_charge WHERE charge_id = charge.id); '
            .'piece := EXISTS (SELECT 1 FROM pieces_de_charge WHERE charge_id = charge.id); '
            ."IF (charge.etat IN ('a_valider', 'validee') AND NOT lignes) OR (charge.etat = 'piece_sans_chiffres' AND (lignes OR NOT piece)) OR (charge.etat = 'validee' AND NOT piece) "
            ."THEN RAISE EXCEPTION '%', ".self::message(self::COHERENTE, self::REFUS_INCOHERENTE).'; END IF; RETURN NULL; END');
    }

    /**
     * @param  literal-string  $nom
     * @param  literal-string  $corps
     */
    private static function fonction(string $nom, string $corps): void
    {
        DB::connection()->unprepared("CREATE OR REPLACE FUNCTION garde_{$nom}() RETURNS trigger LANGUAGE plpgsql ".DeclencheursDeProtection::CHEMIN_DE_RECHERCHE." AS \$\$ {$corps} \$\$");
    }

    /**
     * Retire les déclencheurs de cette table, puis leurs fonctions.
     *
     * @param  literal-string  $table
     * @param  list<literal-string>  $gardes
     */
    private static function retirer(string $table, array $gardes): void
    {
        foreach ($gardes as $garde) {
            DB::connection()->unprepared("DROP TRIGGER IF EXISTS {$garde} ON {$table}");
            DB::connection()->unprepared("DROP FUNCTION IF EXISTS garde_{$garde}()");
        }
    }

    /**
     * Le message d'un refus, préfixé du nom de sa garde, en littéral SQL
     * (voir LitteralSql).
     *
     * @param  literal-string  $garde
     * @param  literal-string  $raison
     * @return literal-string
     */
    private static function message(string $garde, string $raison): string
    {
        return LitteralSql::de("{$garde} : {$raison}");
    }
}

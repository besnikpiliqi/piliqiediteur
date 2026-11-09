<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Support\Facades\DB;

/**
 * Les gardes par lesquelles la base elle-même protège les identifiants de
 * connexion (voir la migration de la table identifiants), même contre
 * DB::table(), une requête brute ou une action défectueuse :
 * - un identifiant a 8 chiffres, le premier de 1 à 9 ;
 * - aucune suppression ni aucun vidage : un identifiant n'est jamais
 *   redonné, même après le retrait de son compte ;
 * - aucune modification, sauf le magasin de rattachement, de nul à une
 *   valeur, une seule fois, toutes les autres colonnes inchangées : un
 *   compte de membre ne change jamais de magasin ;
 * - le magasin de démonstration n'est accepté qu'à l'insertion, pour un
 *   compte de membre (un compte d'entraînement), jamais au passage de nul
 *   à une valeur : aucun compte réel ne devient pour toujours un compte
 *   d'entraînement.
 *
 * Une contrainte CHECK pour la forme, une fonction plpgsql et ses
 * déclencheurs BEFORE INSERT, UPDATE et DELETE pour chaque ligne, et
 * BEFORE TRUNCATE pour l'instruction. Les déclencheurs disparaissent avec
 * la table ; la fonction se retire par retirer(), après la table.
 */
final class GardesDesIdentifiants
{
    private const DEMONSTRATION = 'EXISTS (SELECT 1 FROM magasins WHERE id = NEW.magasin_id AND est_demonstration = TRUE)';

    private const COMPTE_DE_MEMBRE = "COALESCE((SELECT role_societe FROM users WHERE id = NEW.user_id), '') = 'membre'";

    private const MESSAGE_EFFACEMENT = 'identifiants : un identifiant ne s\'efface jamais';

    private const MESSAGE_MODIFICATION = 'identifiants : seul le magasin de rattachement s\'écrit, une seule fois';

    private const MESSAGE_DEMONSTRATION_APRES_COUP = 'identifiants : aucun compte ne se rattache après coup au magasin de démonstration';

    private const MESSAGE_ENTRAINEMENT = 'identifiants : seul un compte de membre se rattache au magasin de démonstration';

    /**
     * Les colonnes qu'aucune modification ne change.
     */
    private const COLONNES_FIGEES = ['id', 'identifiant', 'societe_id', 'user_id', 'jeton_de_creation', 'empreinte_de_creation', 'created_at'];

    public static function poser(): void
    {
        $connexion = DB::connection();
        $figees = implode(' OR ', array_map(static fn (string $colonne): string => "NEW.{$colonne} IS DISTINCT FROM OLD.{$colonne}", self::COLONNES_FIGEES));

        $connexion->unprepared("ALTER TABLE identifiants ADD CONSTRAINT identifiants_forme CHECK (identifiant ~ '^[1-9][0-9]{7}$')");
        $connexion->unprepared('CREATE OR REPLACE FUNCTION garde_identifiants() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN '
            ."IF TG_OP IN ('DELETE', 'TRUNCATE') THEN RAISE EXCEPTION '%', ".self::litteral(self::MESSAGE_EFFACEMENT).'; END IF; '
            ."IF TG_OP = 'INSERT' AND NEW.magasin_id IS NOT NULL AND ".self::DEMONSTRATION.' AND NOT '.self::COMPTE_DE_MEMBRE." THEN RAISE EXCEPTION '%', ".self::litteral(self::MESSAGE_ENTRAINEMENT).'; END IF; '
            ."IF TG_OP = 'UPDATE' THEN "
            ."IF {$figees} OR (OLD.magasin_id IS NOT NULL AND NEW.magasin_id IS DISTINCT FROM OLD.magasin_id) THEN RAISE EXCEPTION '%', ".self::litteral(self::MESSAGE_MODIFICATION).'; END IF; '
            .'IF OLD.magasin_id IS NULL AND NEW.magasin_id IS NOT NULL AND '.self::DEMONSTRATION." THEN RAISE EXCEPTION '%', ".self::litteral(self::MESSAGE_DEMONSTRATION_APRES_COUP).'; END IF; '
            .'END IF; '
            .'RETURN NEW; END $$');
        $connexion->unprepared('CREATE TRIGGER garde_identifiants BEFORE INSERT OR UPDATE OR DELETE ON identifiants FOR EACH ROW EXECUTE FUNCTION garde_identifiants()');
        $connexion->unprepared('CREATE TRIGGER garde_identifiants_vidage BEFORE TRUNCATE ON identifiants FOR EACH STATEMENT EXECUTE FUNCTION garde_identifiants()');
    }

    /**
     * Retire la fonction des gardes, une fois la table supprimée.
     */
    public static function retirer(): void
    {
        DB::connection()->unprepared('DROP FUNCTION IF EXISTS garde_identifiants()');
    }

    /**
     * Un message en littéral SQL (apostrophes doublées).
     */
    private static function litteral(string $message): string
    {
        return "'".str_replace("'", "''", $message)."'";
    }
}

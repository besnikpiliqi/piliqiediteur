<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

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
 * PostgreSQL : une contrainte CHECK pour la forme, une fonction plpgsql et
 * ses déclencheurs BEFORE INSERT, UPDATE et DELETE pour chaque ligne, et
 * BEFORE TRUNCATE pour l'instruction. SQLite : des déclencheurs BEFORE
 * INSERT, UPDATE et DELETE (SQLite n'a pas de TRUNCATE, et une contrainte
 * ne s'y ajoute pas après la création de la table).
 *
 * Seuls PostgreSQL et SQLite sont pris en charge : sur tout autre pilote,
 * la migration échoue au lieu de laisser les identifiants sans garde. Les
 * déclencheurs disparaissent avec la table ; la fonction, sous PostgreSQL,
 * se retire par retirer(), après la table.
 */
final class GardesDesIdentifiants
{
    private const DEMONSTRATION = 'EXISTS (SELECT 1 FROM magasins WHERE id = NEW.magasin_id AND est_demonstration = TRUE)';

    private const COMPTE_DE_MEMBRE = "COALESCE((SELECT role_societe FROM users WHERE id = NEW.user_id), '') = 'membre'";

    private const MESSAGE_FORME = 'identifiants : un identifiant compte 8 chiffres, le premier de 1 à 9';

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

        $instructions = match ($pilote = $connexion->getDriverName()) {
            'pgsql' => self::instructionsPostgres(),
            'sqlite' => self::instructionsSqlite(),
            default => throw new RuntimeException("Les identifiants doivent être gardés par la base : le pilote de base de données « {$pilote} » n'est pas pris en charge (PostgreSQL ou SQLite)."),
        };

        foreach ($instructions as $instruction) {
            $connexion->unprepared($instruction);
        }
    }

    /**
     * Retire la fonction des gardes sous PostgreSQL, une fois la table
     * supprimée ; rien sous SQLite.
     */
    public static function retirer(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::connection()->unprepared('DROP FUNCTION IF EXISTS garde_identifiants()');
        }
    }

    /**
     * @return list<string>
     */
    private static function instructionsPostgres(): array
    {
        $figees = implode(' OR ', array_map(static fn (string $colonne): string => "NEW.{$colonne} IS DISTINCT FROM OLD.{$colonne}", self::COLONNES_FIGEES));

        return [
            "ALTER TABLE identifiants ADD CONSTRAINT identifiants_forme CHECK (identifiant ~ '^[1-9][0-9]{7}$')",
            'CREATE OR REPLACE FUNCTION garde_identifiants() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN '
                ."IF TG_OP IN ('DELETE', 'TRUNCATE') THEN RAISE EXCEPTION '%', ".self::litteral(self::MESSAGE_EFFACEMENT).'; END IF; '
                ."IF TG_OP = 'INSERT' AND NEW.magasin_id IS NOT NULL AND ".self::DEMONSTRATION.' AND NOT '.self::COMPTE_DE_MEMBRE." THEN RAISE EXCEPTION '%', ".self::litteral(self::MESSAGE_ENTRAINEMENT).'; END IF; '
                ."IF TG_OP = 'UPDATE' THEN "
                ."IF {$figees} OR (OLD.magasin_id IS NOT NULL AND NEW.magasin_id IS DISTINCT FROM OLD.magasin_id) THEN RAISE EXCEPTION '%', ".self::litteral(self::MESSAGE_MODIFICATION).'; END IF; '
                .'IF OLD.magasin_id IS NULL AND NEW.magasin_id IS NOT NULL AND '.self::DEMONSTRATION." THEN RAISE EXCEPTION '%', ".self::litteral(self::MESSAGE_DEMONSTRATION_APRES_COUP).'; END IF; '
                .'END IF; '
                .'RETURN NEW; END $$',
            'CREATE TRIGGER garde_identifiants BEFORE INSERT OR UPDATE OR DELETE ON identifiants FOR EACH ROW EXECUTE FUNCTION garde_identifiants()',
            'CREATE TRIGGER garde_identifiants_vidage BEFORE TRUNCATE ON identifiants FOR EACH STATEMENT EXECUTE FUNCTION garde_identifiants()',
        ];
    }

    /**
     * @return list<string>
     */
    private static function instructionsSqlite(): array
    {
        $figees = implode(' OR ', array_map(static fn (string $colonne): string => "NEW.{$colonne} IS NOT OLD.{$colonne}", self::COLONNES_FIGEES));

        return [
            'CREATE TRIGGER identifiants_garde_insert BEFORE INSERT ON identifiants BEGIN '
                .'SELECT RAISE(ABORT, '.self::litteral(self::MESSAGE_FORME).") WHERE NEW.identifiant IS NULL OR NEW.identifiant NOT GLOB '[1-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]'; "
                .'SELECT RAISE(ABORT, '.self::litteral(self::MESSAGE_ENTRAINEMENT).') WHERE NEW.magasin_id IS NOT NULL AND '.self::DEMONSTRATION.' AND NOT '.self::COMPTE_DE_MEMBRE.'; '
                .'END',
            'CREATE TRIGGER identifiants_garde_update BEFORE UPDATE ON identifiants BEGIN '
                .'SELECT RAISE(ABORT, '.self::litteral(self::MESSAGE_MODIFICATION).") WHERE {$figees} OR (OLD.magasin_id IS NOT NULL AND NEW.magasin_id IS NOT OLD.magasin_id); "
                .'SELECT RAISE(ABORT, '.self::litteral(self::MESSAGE_DEMONSTRATION_APRES_COUP).') WHERE OLD.magasin_id IS NULL AND NEW.magasin_id IS NOT NULL AND '.self::DEMONSTRATION.'; '
                .'END',
            'CREATE TRIGGER identifiants_garde_delete BEFORE DELETE ON identifiants BEGIN SELECT RAISE(ABORT, '.self::litteral(self::MESSAGE_EFFACEMENT).'); END',
        ];
    }

    /**
     * Un message en littéral SQL (apostrophes doublées).
     */
    private static function litteral(string $message): string
    {
        return "'".str_replace("'", "''", $message)."'";
    }
}

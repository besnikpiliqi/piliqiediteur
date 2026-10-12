<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Les gardes par lesquelles la base elle-même refuse un état des droits
 * incohérent avec le rôle de société d'un compte, même écrit par
 * DB::table(), une requête brute ou une action défectueuse. Elles
 * s'ajoutent aux lectures qui échouent fermées selon le rôle (voir
 * User::autorisationsDans()) et aux suppressions que font les actions des
 * droits, dans l'ordre de leurs transitions (voir JournalDesDroits) :
 * - affectations_coherentes_avec_le_role (affectations) : un rôle de
 *   magasin qui n'est ni nul ni responsable, vendeur ou observateur ;
 *   l'affectation du propriétaire ou d'un expert-comptable ; une
 *   affectation sans rôle hors d'un administrateur, ou avec rôle pour un
 *   administrateur ; une affectation à tous les magasins avec un rôle ;
 *   une affectation qui devient celle d'un responsable avec des cases ; un
 *   administrateur à la fois affecté à tous les magasins et à une
 *   sélection ;
 * - autorisations_affectations_sans_responsable (autorisations_affectations) :
 *   une case sur l'affectation d'un responsable, qui a toutes les
 *   autorisations de son magasin sans case ;
 * - autorisations_societe_administrateur_seul (autorisations_societe) :
 *   une autorisation de société pour un compte qui n'est pas
 *   administrateur ;
 * - users_role_coherent_avec_les_droits (users) : un rôle de société
 *   inconnu ; devenir propriétaire ou expert-comptable avec une
 *   affectation ou une autorisation de société ; devenir administrateur
 *   avec l'affectation d'un membre ; devenir membre avec l'affectation d'un
 *   administrateur ou une autorisation de société.
 *
 * PostgreSQL : une fonction plpgsql et un déclencheur BEFORE INSERT OR
 * UPDATE par garde. Chaque fonction verrouille d'abord la ligne parente
 * qu'elle lit : la ligne du compte (FOR NO KEY UPDATE) pour une
 * affectation, si bien que deux écritures d'affectations du même compte,
 * ou une affectation et un changement de rôle, se suivent toujours et que
 * la seconde voit la première ; la ligne de l'affectation (FOR SHARE) pour
 * une case ; la ligne du compte (FOR SHARE) pour une autorisation de
 * société. Le FOR KEY SHARE d'une clé étrangère ne suffirait pas : une
 * garde et un changement simultanés passeraient tous les deux. Ces verrous
 * se prennent dans la transaction qui écrit, après ceux qu'elle tient déjà
 * (les registres, puis les comptes, voir JournalDesDroits) : aucun
 * interblocage.
 *
 * SQLite : un déclencheur BEFORE INSERT et un BEFORE UPDATE par garde
 * (BEFORE UPDATE OF role_societe pour users), qui refusent par
 * RAISE(ABORT, ...) ; l'écriture y est déjà sérialisée.
 *
 * Elles se posent quand toutes les tables qu'elles lisent existent (voir
 * la migration des autorisations de société) et se retirent avant elles,
 * fonctions comprises, par retirer(). Seuls PostgreSQL et SQLite sont pris
 * en charge : sur tout autre pilote, la migration échoue au lieu de
 * laisser les droits sans garde.
 */
final class GardesDesDroits
{
    public const AFFECTATIONS = 'affectations_coherentes_avec_le_role';

    public const CASES = 'autorisations_affectations_sans_responsable';

    public const AUTORISATIONS_SOCIETE = 'autorisations_societe_administrateur_seul';

    public const ROLES = 'users_role_coherent_avec_les_droits';

    /**
     * La table que protège chaque garde.
     */
    private const TABLES = [
        self::AFFECTATIONS => 'affectations',
        self::CASES => 'autorisations_affectations',
        self::AUTORISATIONS_SOCIETE => 'autorisations_societe',
        self::ROLES => 'users',
    ];

    public static function poser(): void
    {
        $connexion = DB::connection();

        $instructions = match ($pilote = $connexion->getDriverName()) {
            'pgsql' => self::instructionsPostgres(),
            'sqlite' => self::instructionsSqlite(),
            default => throw new RuntimeException("Les droits doivent être gardés par la base : le pilote de base de données « {$pilote} » n'est pas pris en charge (PostgreSQL ou SQLite)."),
        };

        foreach ($instructions as $instruction) {
            $connexion->unprepared($instruction);
        }
    }

    /**
     * Retire les déclencheurs des gardes, puis, sous PostgreSQL, leurs
     * fonctions, que les déclencheurs empêcheraient sinon de retirer.
     */
    public static function retirer(): void
    {
        $connexion = DB::connection();
        $postgres = $connexion->getDriverName() === 'pgsql';

        foreach (self::TABLES as $garde => $table) {
            if ($postgres) {
                $connexion->unprepared("DROP TRIGGER IF EXISTS {$garde} ON {$table}");
                $connexion->unprepared("DROP FUNCTION IF EXISTS garde_{$garde}()");

                continue;
            }

            foreach (self::declencheursSqlite($garde) as $declencheur) {
                $connexion->unprepared("DROP TRIGGER IF EXISTS {$declencheur}");
            }
        }
    }

    /**
     * Les noms des déclencheurs d'une garde sous SQLite, un par opération
     * (voir aussi TestCase::retirerLaGardeDesDroits()).
     *
     * @return list<string>
     */
    public static function declencheursSqlite(string $garde): array
    {
        return ["{$garde}_insert", "{$garde}_update"];
    }

    /**
     * Les refus de chaque garde : la condition qui refuse, en SQL commun
     * aux deux moteurs (NEW désigne la ligne écrite ; sous SQLite, NEW.id
     * n'est pas encore connu avant l'insertion, d'où la comparaison à
     * COALESCE(NEW.id, 0)), et son message.
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private static function refus(): array
    {
        $rolesDeMagasin = self::liste(array_map(static fn (RoleMagasin $role): string => $role->value, RoleMagasin::cases()));
        $rolesDeSociete = self::liste(array_map(static fn (RoleSociete $role): string => $role->value, RoleSociete::cases()));
        $sansAffectation = self::liste([RoleSociete::Proprietaire->value, RoleSociete::ExpertComptable->value]);
        $administrateur = "'".RoleSociete::Administrateur->value."'";
        $membre = "'".RoleSociete::Membre->value."'";
        $responsable = "'".RoleMagasin::Responsable->value."'";
        $roleDuCompte = 'COALESCE((SELECT role_societe FROM users WHERE id = NEW.user_id), \'\')';
        $autresAffectations = 'SELECT 1 FROM affectations WHERE user_id = NEW.user_id AND id <> COALESCE(NEW.id, 0)';

        return [
            self::AFFECTATIONS => [
                ["NEW.role IS NOT NULL AND NEW.role NOT IN ({$rolesDeMagasin})", 'rôle de magasin inconnu'],
                ["{$roleDuCompte} IN ({$sansAffectation})", 'le propriétaire et un expert-comptable n\'ont aucune affectation'],
                ["NEW.role IS NULL AND {$roleDuCompte} <> {$administrateur}", 'seul un administrateur a une affectation sans rôle'],
                ["NEW.role IS NOT NULL AND {$roleDuCompte} = {$administrateur}", 'un administrateur n\'a aucun rôle de magasin'],
                ['NEW.magasin_id IS NULL AND NEW.role IS NOT NULL', 'une affectation à tous les magasins n\'a pas de rôle de magasin'],
                ["NEW.role = {$responsable} AND EXISTS (SELECT 1 FROM autorisations_affectations WHERE affectation_id = NEW.id)", 'un responsable n\'a aucune case'],
                ["NEW.magasin_id IS NULL AND EXISTS ({$autresAffectations} AND magasin_id IS NOT NULL)", 'un administrateur a accès à tous les magasins ou à une sélection, jamais aux deux'],
                ["NEW.magasin_id IS NOT NULL AND EXISTS ({$autresAffectations} AND magasin_id IS NULL)", 'un administrateur a accès à tous les magasins ou à une sélection, jamais aux deux'],
            ],
            self::CASES => [
                ["COALESCE((SELECT role FROM affectations WHERE id = NEW.affectation_id), '') = {$responsable}", 'un responsable n\'a aucune case'],
            ],
            self::AUTORISATIONS_SOCIETE => [
                ["{$roleDuCompte} <> {$administrateur}", 'seul un administrateur a des autorisations de société'],
            ],
            self::ROLES => [
                ["NEW.role_societe NOT IN ({$rolesDeSociete})", 'rôle de société inconnu'],
                ["NEW.role_societe IN ({$sansAffectation}) AND (EXISTS (SELECT 1 FROM affectations WHERE user_id = NEW.id) OR EXISTS (SELECT 1 FROM autorisations_societe WHERE user_id = NEW.id))", 'le propriétaire et un expert-comptable n\'ont ni affectation ni autorisation de société'],
                ["NEW.role_societe = {$administrateur} AND EXISTS (SELECT 1 FROM affectations WHERE user_id = NEW.id AND role IS NOT NULL)", 'un administrateur n\'a aucune affectation de membre'],
                ["NEW.role_societe = {$membre} AND (EXISTS (SELECT 1 FROM affectations WHERE user_id = NEW.id AND role IS NULL) OR EXISTS (SELECT 1 FROM autorisations_societe WHERE user_id = NEW.id))", 'un membre n\'a ni affectation d\'administrateur ni autorisation de société'],
            ],
        ];
    }

    /**
     * La ligne parente que chaque fonction verrouille avant de lire, sous
     * PostgreSQL (voir le docblock de la classe) ; aucune pour users, dont
     * la ligne écrite est déjà verrouillée.
     */
    private static function verrou(string $garde): string
    {
        return match ($garde) {
            self::AFFECTATIONS => 'PERFORM 1 FROM users WHERE id = NEW.user_id FOR NO KEY UPDATE;',
            self::CASES => 'PERFORM 1 FROM affectations WHERE id = NEW.affectation_id FOR SHARE;',
            self::AUTORISATIONS_SOCIETE => 'PERFORM 1 FROM users WHERE id = NEW.user_id FOR SHARE;',
            default => '',
        };
    }

    /**
     * @return list<string>
     */
    private static function instructionsPostgres(): array
    {
        $instructions = [];

        foreach (self::refus() as $garde => $refus) {
            $controles = implode(' ', array_map(
                static fn (array $un): string => "IF {$un[0]} THEN RAISE EXCEPTION '%', ".self::message($garde, $un[1]).'; END IF;',
                $refus,
            ));
            $table = self::TABLES[$garde];
            $quand = $garde === self::ROLES ? 'INSERT OR UPDATE OF role_societe' : 'INSERT OR UPDATE';

            $instructions[] = "CREATE OR REPLACE FUNCTION garde_{$garde}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN ".self::verrou($garde)." {$controles} RETURN NEW; END \$\$";
            $instructions[] = "CREATE TRIGGER {$garde} BEFORE {$quand} ON {$table} FOR EACH ROW EXECUTE FUNCTION garde_{$garde}()";
        }

        return $instructions;
    }

    /**
     * @return list<string>
     */
    private static function instructionsSqlite(): array
    {
        $instructions = [];

        foreach (self::refus() as $garde => $refus) {
            $controles = implode(' ', array_map(
                static fn (array $un): string => 'SELECT RAISE(ABORT, '.self::message($garde, $un[1]).") WHERE {$un[0]};",
                $refus,
            ));
            $table = self::TABLES[$garde];
            [$insertion, $modification] = self::declencheursSqlite($garde);
            $quandModifie = $garde === self::ROLES ? 'UPDATE OF role_societe' : 'UPDATE';

            $instructions[] = "CREATE TRIGGER {$insertion} BEFORE INSERT ON {$table} BEGIN {$controles} END";
            $instructions[] = "CREATE TRIGGER {$modification} BEFORE {$quandModifie} ON {$table} BEGIN {$controles} END";
        }

        return $instructions;
    }

    /**
     * Le message d'un refus, préfixé du nom de sa garde, en littéral SQL
     * (apostrophes doublées).
     */
    private static function message(string $garde, string $raison): string
    {
        return "'".str_replace("'", "''", "{$garde} : {$raison}")."'";
    }

    /**
     * @param  list<string>  $valeurs
     */
    private static function liste(array $valeurs): string
    {
        return implode(', ', array_map(static fn (string $valeur): string => "'{$valeur}'", $valeurs));
    }
}

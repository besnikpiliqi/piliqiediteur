<?php

namespace App\Domain\Cabinets\Support;

use App\Domain\Tenancy\Enums\TypeDActivite;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Les gardes par lesquelles la base elle-même tient l'espace des cabinets
 * d'expertise comptable, même écrit par DB::table(), une requête brute ou
 * une action défectueuse :
 * - cabinets_de_type_cabinet (cabinets) : la ligne de vérification d'une
 *   société qui n'est pas de type cabinet (voir Cabinet).
 *
 * Chacune se pose dans la migration de création de sa table, et se retire
 * avant elle, fonction comprise, par retirer(). Le type d'une société ne
 * changeant jamais (voir GardesDesTypesDActivite), une garde qui le lit ne
 * verrouille rien : aucune écriture simultanée ne peut le rendre faux.
 *
 * PostgreSQL : une fonction plpgsql et un déclencheur BEFORE par garde
 * (INSERT, ou INSERT OR UPDATE OF les colonnes qu'elle lit). SQLite : un
 * déclencheur BEFORE INSERT et, s'il y a lieu, un BEFORE UPDATE OF ces
 * colonnes par garde, qui refusent par RAISE(ABORT, ...). Seuls
 * PostgreSQL et SQLite sont pris en charge : sur tout autre pilote, la
 * migration échoue au lieu de laisser la table sans garde.
 */
final class GardesDesCabinets
{
    public const CABINETS = 'cabinets_de_type_cabinet';

    /**
     * Un refus vaut à l'insertion et à la modification, à l'insertion
     * seulement, ou à la modification seulement (il lit alors OLD).
     */
    private const TOUJOURS = 'toujours';

    /**
     * La table que protège chaque garde, et les colonnes dont la
     * modification la déclenche, séparées par des virgules (null : à
     * l'insertion seulement).
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private const TABLES = [
        self::CABINETS => ['cabinets', 'societe_id'],
    ];

    public static function poser(string $garde): void
    {
        [$table, $colonnes] = self::TABLES[$garde] ?? throw new InvalidArgumentException("Garde inconnue : {$garde}.");
        $connexion = DB::connection();

        $instructions = match ($pilote = $connexion->getDriverName()) {
            'pgsql' => self::instructionsPostgres($garde, $table, $colonnes),
            'sqlite' => self::instructionsSqlite($garde, $table, $colonnes),
            default => throw new RuntimeException("L'espace des cabinets doit être gardé par la base : le pilote de base de données « {$pilote} » n'est pas pris en charge (PostgreSQL ou SQLite)."),
        };

        foreach ($instructions as $instruction) {
            $connexion->unprepared($instruction);
        }
    }

    /**
     * Retire le déclencheur de la garde puis, sous PostgreSQL, sa fonction,
     * que le déclencheur empêcherait sinon de retirer : dans le down() de
     * la migration, avant sa table.
     */
    public static function retirer(string $garde): void
    {
        [$table] = self::TABLES[$garde] ?? throw new InvalidArgumentException("Garde inconnue : {$garde}.");
        $connexion = DB::connection();

        if ($connexion->getDriverName() === 'pgsql') {
            $connexion->unprepared("DROP TRIGGER IF EXISTS {$garde} ON {$table}");
            $connexion->unprepared("DROP FUNCTION IF EXISTS garde_{$garde}()");

            return;
        }

        foreach (["{$garde}_insert", "{$garde}_update"] as $declencheur) {
            $connexion->unprepared("DROP TRIGGER IF EXISTS {$declencheur}");
        }
    }

    /**
     * Les déclencheurs SQLite d'une garde, pour qu'un test les retire comme
     * le ferait un administrateur de la base.
     *
     * @return list<string>
     */
    public static function declencheursSqlite(string $garde): array
    {
        [, $colonnes] = self::TABLES[$garde] ?? throw new InvalidArgumentException("Garde inconnue : {$garde}.");

        return $colonnes === null ? ["{$garde}_insert"] : ["{$garde}_insert", "{$garde}_update"];
    }

    /**
     * Les refus de chaque garde : la condition qui refuse, en SQL commun
     * aux deux moteurs (NEW désigne la ligne écrite, OLD la ligne d'avant
     * une modification), son message, et quand elle vaut.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function refus(string $garde): array
    {
        $cabinet = "'".TypeDActivite::Cabinet->value."'";

        return match ($garde) {
            self::CABINETS => [
                ["COALESCE((SELECT type_activite FROM societes WHERE id = NEW.societe_id), '') <> {$cabinet}", 'seul un cabinet d\'expertise comptable a une vérification de cabinet', self::TOUJOURS],
            ],
        };
    }

    /**
     * @return list<string>
     */
    private static function instructionsPostgres(string $garde, string $table, ?string $colonnes): array
    {
        $controles = implode(' ', array_map(
            static fn (array $un): string => match ($un[2]) {
                self::TOUJOURS => '',
                default => "IF TG_OP = '{$un[2]}' THEN ",
            }."IF {$un[0]} THEN RAISE EXCEPTION '%', ".self::message($garde, $un[1]).'; END IF;'.($un[2] === self::TOUJOURS ? '' : ' END IF;'),
            self::refus($garde),
        ));
        $moments = $colonnes === null ? 'INSERT' : "INSERT OR UPDATE OF {$colonnes}";

        return [
            "CREATE OR REPLACE FUNCTION garde_{$garde}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN {$controles} RETURN NEW; END \$\$",
            "CREATE TRIGGER {$garde} BEFORE {$moments} ON {$table} FOR EACH ROW EXECUTE FUNCTION garde_{$garde}()",
        ];
    }

    /**
     * @return list<string>
     */
    private static function instructionsSqlite(string $garde, string $table, ?string $colonnes): array
    {
        $controles = static fn (string $moment): string => implode(' ', array_map(
            static fn (array $un): string => 'SELECT RAISE(ABORT, '.self::message($garde, $un[1]).") WHERE {$un[0]};",
            array_filter(self::refus($garde), static fn (array $un): bool => $un[2] === self::TOUJOURS || $un[2] === $moment),
        ));
        $instructions = ["CREATE TRIGGER {$garde}_insert BEFORE INSERT ON {$table} BEGIN {$controles('INSERT')} END"];

        if ($colonnes !== null) {
            $instructions[] = "CREATE TRIGGER {$garde}_update BEFORE UPDATE OF {$colonnes} ON {$table} BEGIN {$controles('UPDATE')} END";
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
}

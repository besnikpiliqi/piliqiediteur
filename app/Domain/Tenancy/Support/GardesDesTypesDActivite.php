<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Enums\TypeDActivite;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Les gardes par lesquelles la base elle-même tient le type d'activité
 * d'une société (voir TypeDActivite), même écrit par DB::table(), une
 * requête brute ou une action défectueuse :
 * - societes_type_d_activite_connu_et_fixe (societes) : un type hors de
 *   ceux de l'énumération, et toute modification du type, d'aucun type
 *   vers aucun autre ;
 * - magasins_jamais_dans_un_cabinet (magasins) : un magasin, démonstration
 *   comprise, dans une société de type cabinet d'expertise comptable.
 *
 * Chacune se pose dans la migration de création de sa table, et se retire
 * avant elle, fonction comprise, par retirer(). Le type d'une société ne
 * changeant jamais, une garde qui le lit par la société d'une ligne ne
 * verrouille rien : aucune écriture simultanée ne peut le rendre faux.
 *
 * PostgreSQL : une fonction plpgsql et un déclencheur BEFORE par garde
 * (INSERT OR UPDATE OF la colonne qu'elle lit). SQLite : un déclencheur
 * BEFORE INSERT et un BEFORE UPDATE OF cette colonne par garde, qui
 * refusent par RAISE(ABORT, ...). Seuls PostgreSQL et SQLite sont pris en
 * charge : sur tout autre pilote, la migration échoue au lieu de laisser
 * le type sans garde.
 */
final class GardesDesTypesDActivite
{
    public const TYPE_FIXE = 'societes_type_d_activite_connu_et_fixe';

    public const MAGASINS = 'magasins_jamais_dans_un_cabinet';

    /**
     * La table que protège chaque garde, et la colonne dont la modification
     * la déclenche.
     */
    private const TABLES = [
        self::TYPE_FIXE => ['societes', 'type_activite'],
        self::MAGASINS => ['magasins', 'societe_id'],
    ];

    public static function poser(string $garde): void
    {
        [$table, $colonne] = self::TABLES[$garde] ?? throw new InvalidArgumentException("Garde inconnue : {$garde}.");
        $connexion = DB::connection();

        $instructions = match ($pilote = $connexion->getDriverName()) {
            'pgsql' => self::instructionsPostgres($garde, $table, $colonne),
            'sqlite' => self::instructionsSqlite($garde, $table, $colonne),
            default => throw new RuntimeException("Le type d'activité des sociétés doit être gardé par la base : le pilote de base de données « {$pilote} » n'est pas pris en charge (PostgreSQL ou SQLite)."),
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
     * Les refus de chaque garde : la condition qui refuse, en SQL commun
     * aux deux moteurs (NEW désigne la ligne écrite, OLD la ligne d'avant
     * une modification), son message, et si elle ne vaut qu'à la
     * modification.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private static function refus(string $garde): array
    {
        $cabinet = "'".TypeDActivite::Cabinet->value."'";

        return match ($garde) {
            self::TYPE_FIXE => [
                ['NEW.type_activite NOT IN ('.implode(', ', array_map(static fn (TypeDActivite $type): string => "'{$type->value}'", TypeDActivite::cases())).')', 'type d\'activité inconnu', false],
                ['NEW.type_activite <> OLD.type_activite', 'le type d\'activité d\'une société ne change jamais', true],
            ],
            self::MAGASINS => [
                ["COALESCE((SELECT type_activite FROM societes WHERE id = NEW.societe_id), '') = {$cabinet}", 'un cabinet d\'expertise comptable n\'a aucun magasin', false],
            ],
        };
    }

    /**
     * @return list<string>
     */
    private static function instructionsPostgres(string $garde, string $table, string $colonne): array
    {
        $controles = implode(' ', array_map(
            static fn (array $un): string => ($un[2] ? "IF TG_OP = 'UPDATE' THEN " : '')."IF {$un[0]} THEN RAISE EXCEPTION '%', ".self::message($garde, $un[1]).'; END IF;'.($un[2] ? ' END IF;' : ''),
            self::refus($garde),
        ));

        return [
            "CREATE OR REPLACE FUNCTION garde_{$garde}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN {$controles} RETURN NEW; END \$\$",
            "CREATE TRIGGER {$garde} BEFORE INSERT OR UPDATE OF {$colonne} ON {$table} FOR EACH ROW EXECUTE FUNCTION garde_{$garde}()",
        ];
    }

    /**
     * @return list<string>
     */
    private static function instructionsSqlite(string $garde, string $table, string $colonne): array
    {
        $controles = static fn (bool $modification): string => implode(' ', array_map(
            static fn (array $un): string => 'SELECT RAISE(ABORT, '.self::message($garde, $un[1]).") WHERE {$un[0]};",
            array_filter(self::refus($garde), static fn (array $un): bool => $modification || ! $un[2]),
        ));

        return [
            "CREATE TRIGGER {$garde}_insert BEFORE INSERT ON {$table} BEGIN {$controles(false)} END",
            "CREATE TRIGGER {$garde}_update BEFORE UPDATE OF {$colonne} ON {$table} BEGIN {$controles(true)} END",
        ];
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

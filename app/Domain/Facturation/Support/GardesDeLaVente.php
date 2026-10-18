<?php

namespace App\Domain\Facturation\Support;

use App\Domain\Tenancy\Enums\RoleSociete;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Les gardes par lesquelles la base elle-même refuse une vente ou un
 * encaissement au nom du propriétaire de la société, qui ne vend jamais
 * (voir PermissionRegistry::duProprietaire()), même écrits par
 * DB::table(), une requête brute ou une action défectueuse ; elles
 * s'ajoutent aux droits, qui ne lui donnent aucune autorisation de vente,
 * comme les gardes des droits s'ajoutent aux lectures (voir
 * GardesDesDroits) :
 * - enregistrements_fiscaux : l'enregistrement signé d'un ticket ou d'une
 *   facture (chaîne des ventes) ou d'un paiement (chaîne des
 *   encaissements) dont l'opérateur est le propriétaire ; un avoir, une
 *   contre-passation, un événement et un enregistrement du système (sans
 *   opérateur) passent ;
 * - factures : une facture ou un ticket créé au nom du propriétaire, ou
 *   qu'on lui attribue ensuite ; seulement à l'insertion et à la
 *   modification de son auteur, si bien qu'un brouillon créé par un
 *   administrateur devenu ensuite propriétaire s'émet toujours par un
 *   autre compte ;
 * - paiements : un paiement enregistré au nom du propriétaire ; sa
 *   contre-passation d'un paiement saisi par erreur passe.
 * Le devis n'a pas d'auteur en base : il n'a pas de garde.
 *
 * Le rôle se lit sur la ligne du compte, sous PostgreSQL verrouillée FOR
 * SHARE, verrou compatible avec celui que la vente tient déjà sur la ligne
 * de son auteur (voir LecteurDesDroits) : une transmission de la propriété,
 * qui verrouille les registres de tous les magasins puis les comptes (voir
 * JournalDesDroits), passe avant ou après, jamais pendant.
 *
 * PostgreSQL : une fonction plpgsql et un déclencheur BEFORE par table.
 * SQLite : un déclencheur BEFORE INSERT (et BEFORE UPDATE OF cree_par pour
 * les factures), qui refuse par RAISE(ABORT, ...). Seuls PostgreSQL et
 * SQLite sont pris en charge : sur tout autre pilote, la migration échoue
 * au lieu de laisser la vente sans garde.
 */
final class GardesDeLaVente
{
    public const MESSAGE = 'Le propriétaire ne vend jamais : aucune vente ni aucun encaissement ne s\'inscrit à son nom.';

    /**
     * Pour chaque table gardée : la colonne de son auteur, la condition
     * qui fait d'une ligne une vente ou un encaissement, et si la garde
     * vaut aussi quand l'auteur d'une ligne change.
     *
     * @var array<string, array{auteur: string, vente: string, modification: bool}>
     */
    private const TABLES = [
        'enregistrements_fiscaux' => [
            'auteur' => 'operateur_id',
            'vente' => "((NEW.chaine = 'vente' AND NEW.type IN ('facture', 'ticket')) OR (NEW.chaine = 'encaissement' AND NEW.type = 'paiement'))",
            'modification' => false,
        ],
        'factures' => [
            'auteur' => 'cree_par',
            'vente' => '1 = 1',
            'modification' => true,
        ],
        'paiements' => [
            'auteur' => 'cree_par',
            'vente' => 'NEW.contre_passe_paiement_id IS NULL',
            'modification' => false,
        ],
    ];

    /**
     * Pose la garde de cette table, une fois la table créée.
     *
     * @throws InvalidArgumentException quand la table n'est pas gardée
     * @throws RuntimeException quand le pilote n'est pas pris en charge
     */
    public static function poser(string $table): void
    {
        $garde = self::garde($table);
        $connexion = DB::connection();

        $instructions = match ($pilote = $connexion->getDriverName()) {
            'pgsql' => self::instructionsPostgres($table, $garde),
            'sqlite' => self::instructionsSqlite($table, $garde),
            default => throw new RuntimeException("La table {$table} doit refuser une vente au nom du propriétaire : le pilote de base de données « {$pilote} » n'est pas pris en charge (PostgreSQL ou SQLite)."),
        };

        foreach ($instructions as $instruction) {
            $connexion->unprepared($instruction);
        }
    }

    /**
     * Retire ce qui reste de la garde une fois sa table supprimée : sous
     * PostgreSQL, sa fonction ; ses déclencheurs disparaissent avec la
     * table.
     *
     * @throws InvalidArgumentException quand la table n'est pas gardée
     */
    public static function retirer(string $table): void
    {
        self::garde($table);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::connection()->unprepared('DROP FUNCTION IF EXISTS '.self::nom($table).'()');
        }
    }

    /**
     * @return array{auteur: string, vente: string, modification: bool}
     *
     * @throws InvalidArgumentException
     */
    private static function garde(string $table): array
    {
        return self::TABLES[$table] ?? throw new InvalidArgumentException("La table {$table} n'a pas de garde de la vente.");
    }

    private static function nom(string $table): string
    {
        return "garde_vente_{$table}";
    }

    /**
     * La condition qui refuse la ligne écrite : une vente ou un
     * encaissement dont l'auteur est, à cet instant, le propriétaire.
     *
     * @param  array{auteur: string, vente: string, modification: bool}  $garde
     */
    private static function refus(array $garde): string
    {
        $proprietaire = "'".RoleSociete::Proprietaire->value."'";

        return "NEW.{$garde['auteur']} IS NOT NULL AND {$garde['vente']} AND EXISTS (SELECT 1 FROM users WHERE id = NEW.{$garde['auteur']} AND role_societe = {$proprietaire})";
    }

    /**
     * @param  array{auteur: string, vente: string, modification: bool}  $garde
     * @return list<string>
     */
    private static function instructionsPostgres(string $table, array $garde): array
    {
        $nom = self::nom($table);
        $quand = $garde['modification'] ? "INSERT OR UPDATE OF {$garde['auteur']}" : 'INSERT';

        return [
            "CREATE OR REPLACE FUNCTION {$nom}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN "
                ."IF NEW.{$garde['auteur']} IS NOT NULL THEN PERFORM 1 FROM users WHERE id = NEW.{$garde['auteur']} FOR SHARE; END IF; "
                .'IF '.self::refus($garde)." THEN RAISE EXCEPTION '%', ".self::message().'; END IF; '
                .'RETURN NEW; END $$',
            "CREATE TRIGGER {$nom} BEFORE {$quand} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$nom}()",
        ];
    }

    /**
     * @param  array{auteur: string, vente: string, modification: bool}  $garde
     * @return list<string>
     */
    private static function instructionsSqlite(string $table, array $garde): array
    {
        $nom = self::nom($table);
        $controle = 'SELECT RAISE(ABORT, '.self::message().') WHERE '.self::refus($garde).';';
        $instructions = ["CREATE TRIGGER {$nom}_insert BEFORE INSERT ON {$table} BEGIN {$controle} END"];

        if ($garde['modification']) {
            $instructions[] = "CREATE TRIGGER {$nom}_update BEFORE UPDATE OF {$garde['auteur']} ON {$table} BEGIN {$controle} END";
        }

        return $instructions;
    }

    /**
     * Le message du refus, en littéral SQL (apostrophes doublées).
     */
    private static function message(): string
    {
        return "'".str_replace("'", "''", self::MESSAGE)."'";
    }
}

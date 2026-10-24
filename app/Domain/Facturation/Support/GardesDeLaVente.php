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
 * - factures : une facture ou un ticket créé au nom du propriétaire, qu'on
 *   lui attribue ensuite, ou qu'on émet alors qu'il en est l'auteur. Un
 *   brouillon créé par un administrateur devenu ensuite propriétaire reste
 *   à son nom tant qu'il n'est qu'un brouillon (il se modifie toujours),
 *   et devient à l'émission la vente de qui l'émet (voir
 *   EmettreFactureAction) ; une vente qu'il avait émise comme
 *   administrateur reste la sienne, et son règlement la modifie toujours ;
 * - paiements : un paiement enregistré au nom du propriétaire ; seule sa
 *   contre-passation d'un paiement saisi par erreur passe, quand elle est
 *   l'exact opposé de l'original (même magasin, même client, même mode,
 *   montant opposé, voir ContrePasserPaiementAction) : un « paiement »
 *   qui désigne un original sans en être l'opposé reste un encaissement.
 * Le devis n'a pas d'auteur en base : il n'a pas de garde.
 *
 * Le rôle se lit sur la ligne du compte, sous PostgreSQL verrouillée FOR
 * SHARE, verrou compatible avec celui que la vente tient déjà sur la ligne
 * de son auteur (voir LecteurDesDroits) : une transmission de la propriété,
 * qui verrouille les registres de tous les magasins puis les comptes (voir
 * JournalDesDroits), passe avant ou après, jamais pendant.
 *
 * PostgreSQL : une fonction plpgsql et un déclencheur BEFORE par table.
 * SQLite : un déclencheur BEFORE INSERT (et, pour les factures, BEFORE
 * UPDATE de leur auteur ou de leur numéro), qui refuse par RAISE(ABORT,
 * ...). Seuls PostgreSQL et SQLite sont pris en charge : sur tout autre
 * pilote, la migration échoue au lieu de laisser la vente sans garde.
 */
final class GardesDeLaVente
{
    public const MESSAGE = 'Le propriétaire ne vend jamais : aucune vente ni aucun encaissement ne s\'inscrit à son nom.';

    /**
     * Pour chaque table gardée : la colonne de son auteur, la condition
     * qui fait d'une ligne ajoutée une vente ou un encaissement et, pour
     * une table dont les lignes se modifient, les colonnes dont la
     * modification se contrôle et la condition qui fait de cette
     * modification une vente attribuée à son auteur.
     *
     * @var array<string, array{auteur: string, vente: string, modification: array{colonnes: list<string>, condition: string}|null}>
     */
    private const TABLES = [
        'enregistrements_fiscaux' => [
            'auteur' => 'operateur_id',
            'vente' => "((NEW.chaine = 'vente' AND NEW.type IN ('facture', 'ticket')) OR (NEW.chaine = 'encaissement' AND NEW.type = 'paiement'))",
            'modification' => null,
        ],
        'factures' => [
            'auteur' => 'cree_par',
            'vente' => '1 = 1',
            'modification' => [
                'colonnes' => ['cree_par', 'numero'],
                'condition' => '(OLD.cree_par IS NULL OR OLD.cree_par <> NEW.cree_par OR (OLD.numero IS NULL AND NEW.numero IS NOT NULL))',
            ],
        ],
        'paiements' => [
            'auteur' => 'cree_par',
            'vente' => '(NEW.contre_passe_paiement_id IS NULL OR NOT EXISTS (SELECT 1 FROM paiements AS original WHERE original.id = NEW.contre_passe_paiement_id'
                .' AND original.contre_passe_paiement_id IS NULL AND original.magasin_id = NEW.magasin_id AND original.client_id = NEW.client_id'
                .' AND original.mode_paiement = NEW.mode_paiement AND original.montant = -NEW.montant))',
            'modification' => null,
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
     * @return array{auteur: string, vente: string, modification: array{colonnes: list<string>, condition: string}|null}
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
     * L'auteur de la ligne écrite est, à cet instant, le propriétaire.
     */
    private static function auteurProprietaire(string $auteur): string
    {
        return "EXISTS (SELECT 1 FROM users WHERE id = NEW.{$auteur} AND role_societe = '".RoleSociete::Proprietaire->value."')";
    }

    /**
     * La fonction lit le rôle de l'auteur, sous le verrou FOR SHARE de sa
     * ligne, seulement quand la ligne écrite est une vente : à l'ajout,
     * selon la condition de la table ; à la modification, selon celle de
     * la modification (jamais OLD lu à l'ajout, où il n'existe pas).
     *
     * @param  array{auteur: string, vente: string, modification: array{colonnes: list<string>, condition: string}|null}  $garde
     * @return list<string>
     */
    private static function instructionsPostgres(string $table, array $garde): array
    {
        $nom = self::nom($table);
        $auteur = $garde['auteur'];
        $quand = $garde['modification'] === null ? 'INSERT' : 'INSERT OR UPDATE OF '.implode(', ', $garde['modification']['colonnes']);
        $estUneVente = $garde['modification'] === null
            ? "IF NOT ({$garde['vente']}) THEN RETURN NEW; END IF; "
            : "IF TG_OP = 'UPDATE' THEN IF NOT ({$garde['modification']['condition']}) THEN RETURN NEW; END IF; "
                ."ELSIF NOT ({$garde['vente']}) THEN RETURN NEW; END IF; ";

        return [
            "CREATE OR REPLACE FUNCTION {$nom}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN "
                ."IF NEW.{$auteur} IS NULL THEN RETURN NEW; END IF; "
                .$estUneVente
                ."PERFORM 1 FROM users WHERE id = NEW.{$auteur} FOR SHARE; "
                .'IF '.self::auteurProprietaire($auteur)." THEN RAISE EXCEPTION '%', ".self::message().'; END IF; '
                .'RETURN NEW; END $$',
            "CREATE TRIGGER {$nom} BEFORE {$quand} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$nom}()",
        ];
    }

    /**
     * @param  array{auteur: string, vente: string, modification: array{colonnes: list<string>, condition: string}|null}  $garde
     * @return list<string>
     */
    private static function instructionsSqlite(string $table, array $garde): array
    {
        $nom = self::nom($table);
        $refus = fn (string $condition): string => 'SELECT RAISE(ABORT, '.self::message().") WHERE NEW.{$garde['auteur']} IS NOT NULL AND {$condition} AND ".self::auteurProprietaire($garde['auteur']).';';
        $instructions = ["CREATE TRIGGER {$nom}_insert BEFORE INSERT ON {$table} BEGIN {$refus($garde['vente'])} END"];

        if ($garde['modification'] !== null) {
            $instructions[] = "CREATE TRIGGER {$nom}_update BEFORE UPDATE OF ".implode(', ', $garde['modification']['colonnes'])." ON {$table} BEGIN {$refus($garde['modification']['condition'])} END";
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

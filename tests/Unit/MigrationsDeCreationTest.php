<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Chaque table est définie par une seule migration de création, qui
 * contient directement son schéma final ; pour changer une table, on
 * modifie sa migration de création et on recrée la base (CLAUDE.md,
 * « Développement : toujours repartir de zéro ») : aucune migration
 * n'ajoute, ne renomme ni ne retire une colonne d'une table qu'une autre
 * crée, et aucune ne recrée une table déjà créée. Ce test lit les
 * migrations, sans base de données.
 */
class MigrationsDeCreationTest extends TestCase
{
    /**
     * Les migrations qui ne créent aucune table : le fuseau par défaut de
     * la base, et la fonction des recherches sans casse ni accents.
     *
     * @var list<string>
     */
    private const SANS_TABLE = [
        '0000_00_00_000000_fixer_le_fuseau_par_defaut_de_la_base.php',
        '2026_10_02_100001_create_fonction_sans_casse_ni_accents.php',
    ];

    public function test_chaque_table_est_definie_par_sa_seule_migration_de_creation(): void
    {
        $fautes = [];
        $createurs = [];

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $chemin) {
            $nom = basename($chemin);
            $code = (string) file_get_contents($chemin);
            $creees = self::tablesCreees($code);

            foreach ($creees as $table) {
                $createurs[$table][] = $nom;
            }

            $fautes = [...$fautes, ...self::fautesDeLaMigration($nom, $code, $creees)];
        }

        foreach ($createurs as $table => $migrations) {
            if (count($migrations) > 1) {
                $fautes[] = $table.' : créée par '.implode(' et ', $migrations);
            }
        }

        $this->assertSame([], $fautes);
    }

    /**
     * La règle refuse une migration qui ajoute une colonne à une table
     * qu'elle ne crée pas, qui renomme une colonne, qui change une table
     * d'une autre migration par une requête, ou dont le nom ne dit pas la
     * table qu'elle crée, et laisse passer une migration de création qui
     * pose une contrainte sur sa propre table.
     *
     * @param  list<string>  $attendues
     */
    #[DataProvider('migrationsDExemple')]
    public function test_la_regle_refuse_une_migration_qui_change_une_table_d_une_autre(string $nom, string $code, array $attendues): void
    {
        $this->assertSame($attendues, self::fautesDeLaMigration($nom, $code, self::tablesCreees($code)));
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function migrationsDExemple(): array
    {
        return [
            'création qui pose sa contrainte' => [
                '2026_01_01_000000_create_exemples_table.php',
                "Schema::create('exemples', function () {}); DB::statement('ALTER TABLE exemples ADD CONSTRAINT exemples_ok CHECK (true)');",
                [],
            ],
            'ajout d’une colonne' => [
                '2026_01_01_000000_add_couleur_to_produits_table.php',
                "Schema::table('produits', function (Blueprint \$table) { \$table->string('couleur'); });",
                [
                    '2026_01_01_000000_add_couleur_to_produits_table.php : son nom ne dit pas la table qu’elle crée',
                    '2026_01_01_000000_add_couleur_to_produits_table.php : change la table produits par Schema::table()',
                ],
            ],
            'renommage d’une colonne' => [
                '2026_01_01_000000_create_exemples_table.php',
                "Schema::create('exemples', function (Blueprint \$table) { \$table->renameColumn('a', 'b'); });",
                ['2026_01_01_000000_create_exemples_table.php : renomme ou retire une colonne'],
            ],
            'requête sur la table d’une autre migration' => [
                '2026_01_01_000000_create_exemples_table.php',
                "Schema::create('exemples', function () {}); DB::statement('ALTER TABLE produits ADD COLUMN couleur text');",
                ['2026_01_01_000000_create_exemples_table.php : change la table produits, qu’elle ne crée pas'],
            ],
        ];
    }

    /**
     * Les fautes de cette migration au regard de la règle.
     *
     * @param  list<string>  $creees
     * @return list<string>
     */
    private static function fautesDeLaMigration(string $nom, string $code, array $creees): array
    {
        $fautes = [];

        if (! in_array($nom, self::SANS_TABLE, true) && (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_create_([a-z0-9_]+)_table\.php$/', $nom, $nomme) !== 1 || ! in_array($nomme[1], $creees, true))) {
            $fautes[] = $nom.' : son nom ne dit pas la table qu’elle crée';
        }

        preg_match_all("/Schema::table\\(\\s*'([a-z0-9_]+)'/", $code, $changees);

        foreach ($changees[1] as $table) {
            $fautes[] = $nom.' : change la table '.$table.' par Schema::table()';
        }

        if (preg_match('/renameColumn\(|RENAME\s+COLUMN|dropColumn\(|DROP\s+COLUMN/i', $code) === 1) {
            $fautes[] = $nom.' : renomme ou retire une colonne';
        }

        preg_match_all('/ALTER\s+TABLE\s+(?:ONLY\s+)?([a-z0-9_]+)/i', $code, $alterees);

        foreach (array_unique($alterees[1]) as $table) {
            if (! in_array($table, $creees, true)) {
                $fautes[] = $nom.' : change la table '.$table.', qu’elle ne crée pas';
            }
        }

        return $fautes;
    }

    /**
     * Les tables que crée cette migration.
     *
     * @return list<string>
     */
    private static function tablesCreees(string $code): array
    {
        preg_match_all("/Schema::create\\(\\s*'([a-z0-9_]+)'/", $code, $creees);

        return array_values(array_unique($creees[1]));
    }
}

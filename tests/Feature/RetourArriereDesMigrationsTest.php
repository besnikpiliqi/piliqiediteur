<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Chaque migration de création doit pouvoir être défaite puis rejouée,
 * dans l'ordre inverse de ses dépendances (une table référencée n'est
 * supprimée qu'après celles qui la référencent). Sous PostgreSQL, rien
 * ne survit non plus dans le schéma : ni les fonctions de refus des
 * tables protégées (voir DeclencheursDeProtection::retirer()), ni la
 * fonction des recherches et l'extension unaccent.
 *
 * DatabaseMigrations, pas RefreshDatabase : comme un vrai déploiement,
 * hors de toute transaction englobante.
 */
class RetourArriereDesMigrationsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_toutes_les_migrations_se_defont_une_par_une_puis_se_rejouent(): void
    {
        $nombreDeMigrations = DB::table('migrations')->count();
        $tablesAvant = $this->tablesDuSchema();
        $fonctionsAvant = $this->fonctionsDuSchema();

        for ($etape = 1; $etape <= $nombreDeMigrations; $etape++) {
            $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        }

        $this->assertSame(0, DB::table('migrations')->count());
        $this->assertSame(['migrations'], $this->tablesDuSchema());
        $this->assertSame([], $this->fonctionsDuSchema());

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame($nombreDeMigrations, DB::table('migrations')->count());
        $this->assertSame($tablesAvant, $this->tablesDuSchema());
        $this->assertSame($fonctionsAvant, $this->fonctionsDuSchema());
    }

    /**
     * Les fonctions du schéma courant sous PostgreSQL, extensions
     * comprises ; SQLite n'en garde aucune.
     *
     * @return list<string>
     */
    private function fonctionsDuSchema(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        return array_column(DB::select('select p.proname from pg_proc p join pg_namespace n on n.oid = p.pronamespace where n.nspname = current_schema() order by p.proname'), 'proname');
    }

    /**
     * @return list<string>
     */
    private function tablesDuSchema(): array
    {
        $tables = array_column(Schema::getTables(), 'name');
        sort($tables);

        return $tables;
    }
}

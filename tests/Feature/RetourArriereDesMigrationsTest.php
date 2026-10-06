<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Chaque migration doit pouvoir être défaite puis rejouée : sous SQLite,
 * supprimer une colonne encore utilisée par un index échoue
 * (add_cloture_caisse_id_to_factures, add_tenancy_columns_to_users), ce
 * qui bloquait tout "php artisan migrate:rollback" au-delà.
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

        for ($etape = 1; $etape <= $nombreDeMigrations; $etape++) {
            $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        }

        $this->assertSame(0, DB::table('migrations')->count());
        $this->assertSame(['migrations'], $this->tablesDuSchema());

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame($nombreDeMigrations, DB::table('migrations')->count());
        $this->assertSame($tablesAvant, $this->tablesDuSchema());
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

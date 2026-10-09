<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Support\DeclencheursAjoutSeul;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Les déclencheurs « ajout seul » ne se posent que là où la base sait
 * refuser elle-même une modification : la migration échoue partout
 * ailleurs. Leur effet sur le registre fiscal est vérifié par
 * RegistreFiscalTest.
 */
class DeclencheursAjoutSeulTest extends TestCase
{
    public function test_la_migration_echoue_sur_un_pilote_de_base_non_pris_en_charge(): void
    {
        config(['database.connections.postgres_de_test' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'piliqi']]);
        DB::setDefaultConnection('postgres_de_test');

        $this->assertThrows(
            fn () => DeclencheursAjoutSeul::poser('enregistrements_fiscaux'),
            RuntimeException::class,
            'La table enregistrements_fiscaux doit refuser toute modification et toute suppression : le pilote de base de données « pgsql » n\'est pas pris en charge (SQLite, MySQL ou MariaDB).',
        );
    }

    public function test_un_nom_de_table_qui_n_est_pas_un_identifiant_simple_est_refuse(): void
    {
        $this->assertThrows(
            fn () => DeclencheursAjoutSeul::poser('enregistrements_fiscaux; DROP TABLE magasins'),
            InvalidArgumentException::class,
            'Nom de table invalide pour un déclencheur : enregistrements_fiscaux; DROP TABLE magasins.',
        );
    }
}

<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Les déclencheurs de protection ne se posent que là où la base sait
 * refuser elle-même une modification ou une suppression : la migration
 * échoue partout ailleurs. Leur effet est vérifié par RegistreFiscalTest
 * (ajout seul) et CreerMagasinActionTest (sans suppression).
 */
class DeclencheursDeProtectionTest extends TestCase
{
    /**
     * Chaque protection, posée sur une table, et ce que la table doit
     * refuser.
     *
     * @return array<string, array{0: Closure(): void, 1: string}>
     */
    public static function protections(): array
    {
        return [
            'ajout seul' => [
                static fn () => DeclencheursDeProtection::ajoutSeul('enregistrements_fiscaux'),
                'La table enregistrements_fiscaux doit refuser toute modification et toute suppression',
            ],
            'sans suppression' => [
                static fn () => DeclencheursDeProtection::sansSuppression('magasins'),
                'La table magasins doit refuser toute suppression définitive',
            ],
        ];
    }

    /**
     * @param  Closure(): void  $poser
     */
    #[DataProvider('protections')]
    public function test_la_migration_echoue_sur_un_pilote_de_base_non_pris_en_charge(Closure $poser, string $exigence): void
    {
        config(['database.connections.postgres_de_test' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'piliqi']]);
        DB::setDefaultConnection('postgres_de_test');

        $this->assertThrows(
            $poser,
            RuntimeException::class,
            $exigence.' : le pilote de base de données « pgsql » n\'est pas pris en charge (SQLite, MySQL ou MariaDB).',
        );
    }

    public function test_un_nom_de_table_qui_n_est_pas_un_identifiant_simple_est_refuse(): void
    {
        $this->assertThrows(
            fn () => DeclencheursDeProtection::ajoutSeul('enregistrements_fiscaux; DROP TABLE magasins'),
            InvalidArgumentException::class,
            'Nom de table invalide pour un déclencheur : enregistrements_fiscaux; DROP TABLE magasins.',
        );
    }
}
